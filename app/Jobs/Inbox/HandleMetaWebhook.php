<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\HideInboxMessages;
use App\Actions\Inbox\ReactToInboxMessages;
use App\Actions\Inbox\RemoveInboxMessages;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Inbox\InboxWebhookEvents;
use App\Dto\Inbox\IncomingMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxPendingMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use App\Services\Inbox\Drivers\ThreadsInboxDriver;
use App\Services\Inbox\InboxDrivers;
use App\Services\Inbox\Meta\MetaDirectMessages;
use App\Support\Inbox\RedactedError;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Routes one Meta delivery (Page `feed` and `mention`, Instagram `comments`,
 * `live_comments` and `mentions`, `messaging` events of Messenger and Instagram, Threads
 * `replies`, `mentions` and `delete`) to every channel that
 * connected the identity it is about, so the same Page connected in two
 * workspaces lands in both inboxes.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/page/
 * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/instagram
 * @see https://developers.facebook.com/docs/threads/webhooks
 */
class HandleMetaWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload)
    {
        $this->onQueue('inbox');
    }

    /** @param array<string, mixed> $payload */
    public static function isRelevant(array $payload): bool
    {
        $changes = collect((array) data_get($payload, 'entry', []))
            ->flatMap(fn (mixed $entry): array => (array) data_get($entry, 'changes', []));

        $messaging = collect((array) data_get($payload, 'entry', []))
            ->flatMap(fn (mixed $entry): array => (array) data_get($entry, 'messaging', []));

        return $messaging->isNotEmpty() || $changes->contains(fn (mixed $change): bool => (data_get($change, 'field') === 'feed' && data_get($change, 'value.item') === 'comment')
            || in_array(data_get($change, 'field'), ['comments', 'live_comments', 'mentions', 'mention'], true))
            || collect(self::threadsValues($payload))->isNotEmpty()
            || collect(self::threadsValues($payload, 'mentions'))->isNotEmpty()
            || collect(self::threadsValues($payload, 'delete'))->isNotEmpty();
    }

    public function handle(): void
    {
        /** @var array<string, array{account: SocialAccount, events: list<array{read: Closure(SocialAccount): InboxWebhookEvents, at: CarbonImmutable}>}> $routed */
        $routed = [];

        foreach ((array) data_get($this->payload, 'entry', []) as $entry) {
            $entryId = (string) data_get($entry, 'id');
            $time = (int) data_get($entry, 'time', now()->getTimestamp());

            $events = (array) data_get($entry, 'messaging', []);
            $messagingAccounts = $events === [] ? new Collection : $this->messagingAccounts((string) data_get($this->payload, 'object'), $entryId);

            foreach ($events as $event) {
                $this->route($routed, $messagingAccounts,
                    fn (SocialAccount $account): InboxWebhookEvents => in_array(ConversationKind::DirectMessage, app(InboxDrivers::class)->kindsFor($account), true)
                        ? app(MetaDirectMessages::class)->webhookEvents($account, (array) $event)
                        : new InboxWebhookEvents,
                    filled(data_get($event, 'timestamp')) ? CarbonImmutable::createFromTimestampMs((int) data_get($event, 'timestamp')) : CarbonImmutable::createFromTimestamp($time));
            }

            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $value = (array) data_get($change, 'value', []);
                $field = data_get($change, 'field');
                $observedAt = CarbonImmutable::createFromTimestamp($time);

                if (data_get($this->payload, 'object') === 'page' && $field === 'feed' && data_get($value, 'item') === 'comment') {
                    $this->route($routed, $this->accounts(fn (Builder $query) => $query
                        ->where('platform', Platform::Facebook)
                        ->where('platform_user_id', $entryId)),
                        fn (SocialAccount $account): InboxWebhookEvents => app(FacebookInboxDriver::class)->webhookEvents($account, $value), $observedAt);
                }

                if (data_get($this->payload, 'object') === 'page' && $field === 'mention') {
                    $this->route($routed, $this->accounts(fn (Builder $query) => $query
                        ->where('platform', Platform::Facebook)
                        ->where('platform_user_id', $entryId)),
                        fn (SocialAccount $account): InboxWebhookEvents => app(FacebookInboxDriver::class)->mentionWebhookEvents($account, $value), $observedAt);
                }

                if (data_get($this->payload, 'object') === 'instagram' && in_array($field, ['comments', 'live_comments'], true)) {
                    $this->route($routed, $this->accounts(fn (Builder $query) => $query
                        ->where(fn (Builder $identity) => $identity
                            ->where(fn (Builder $viaFacebook) => $viaFacebook->where('platform', Platform::InstagramFacebook)->where('platform_user_id', $entryId))
                            ->orWhere(fn (Builder $direct) => $direct->where('platform', Platform::Instagram)->where('meta->ig_user_id', $entryId)))),
                        fn (SocialAccount $account): InboxWebhookEvents => app(InstagramInboxDriver::class)->webhookEvents($account, $value, $time), $observedAt);
                }

                if (data_get($this->payload, 'object') === 'instagram' && $field === 'mentions') {
                    $this->route($routed, $this->accounts(fn (Builder $query) => $query
                        ->where('platform', Platform::InstagramFacebook)
                        ->where('platform_user_id', $entryId)),
                        fn (SocialAccount $account): InboxWebhookEvents => app(InstagramInboxDriver::class)->mentionWebhookEvents($account, $value), $observedAt);
                }
            }
        }

        foreach (self::threadsValues($this->payload) as $value) {
            $ownerId = (string) data_get($value, 'root_post.owner_id');

            if ($ownerId === '') {
                continue;
            }

            $accounts = $this->accounts(fn (Builder $query) => $query
                ->where('platform', Platform::Threads)
                ->where('platform_user_id', $ownerId));

            if ($accounts->isEmpty() && filled(data_get($value, 'replied_to.id'))) {
                $accounts = $this->accounts(fn (Builder $query) => $query
                    ->where('platform', Platform::Threads)
                    ->whereIn('id', InboxMessage::query()
                        ->where('platform_message_hash', InboxConversation::hashOf((string) data_get($value, 'replied_to.id')))
                        ->select('social_account_id')));
            }

            $this->route($routed, $accounts,
                fn (SocialAccount $account): InboxWebhookEvents => app(ThreadsInboxDriver::class)->webhookEvents($account, $value),
                $this->threadsObservedAt());
        }

        foreach (self::threadsValues($this->payload, 'mentions') as $value) {
            $targetId = (string) data_get($this->payload, 'target_id');

            if ($targetId === '') {
                continue;
            }

            $this->route($routed, $this->accounts(fn (Builder $query) => $query
                ->where('platform', Platform::Threads)
                ->where('platform_user_id', $targetId)),
                fn (SocialAccount $account): InboxWebhookEvents => app(ThreadsInboxDriver::class)->mentionWebhookEvents($account, $value),
                $this->threadsObservedAt());
        }

        foreach (self::threadsValues($this->payload, 'delete') as $value) {
            $mediaId = (string) data_get($value, 'id');

            if ($mediaId === '') {
                continue;
            }

            $this->route($routed, $this->accountsStoring(Platform::Threads, $mediaId),
                fn (): InboxWebhookEvents => new InboxWebhookEvents(removedIds: [$mediaId]),
                $this->threadsObservedAt());
        }

        $stored = 0;
        $outage = null;

        foreach ($routed as $route) {
            try {
                $this->store($route['account'], $route['events']);
                $stored++;
            } catch (PlatformUnavailableException $exception) {
                $outage = $exception;
            }
        }

        if ($outage && $stored === 0) {
            throw $outage;
        }
    }

    /**
     * Each message carries the time Meta reported the event, so an older
     * delivery (Meta keeps no order and retries for 36 hours) never
     * overwrites a newer state. A delivery that brings messages marks their
     * kinds as receiving webhooks, which slows their polls to reconciliation.
     *
     * @param  list<array{read: Closure(SocialAccount): InboxWebhookEvents, at: CarbonImmutable}>  $events
     */
    private function store(SocialAccount $account, array $events): void
    {
        try {
            $messages = [];
            $removed = [];
            $reactions = [];
            $hidden = [];
            $shown = [];

            foreach ($events as $event) {
                $result = $event['read']($account);
                $messages = [...$messages, ...array_map(fn (IncomingMessage $message): IncomingMessage => $message->withObservedAt($event['at']), $result->messages)];
                $removed = [...$removed, ...$result->removedIds];
                $reactions = [...$reactions, ...$result->reactions];
                $hidden = [...$hidden, ...$result->hiddenIds];
                $shown = [...$shown, ...$result->shownIds];
            }

            $kinds = app(InboxDrivers::class)->kindsFor($account);
            $messages = array_values(array_filter($messages, fn (IncomingMessage $message): bool => in_array($message->kind, $kinds, true)));

            InboxSyncState::recordWebhook($account, array_map(fn (IncomingMessage $message): ConversationKind => $message->kind, $messages));
            UpsertInboxMessages::execute($account, $messages);
            RemoveInboxMessages::execute($account, $removed);
            ReactToInboxMessages::execute($account, $reactions);
            HideInboxMessages::execute($account, $hidden, true);
            HideInboxMessages::execute($account, $shown, false);
        } catch (TokenExpiredException $exception) {
            $account->markAsTokenExpired($exception->getMessage());
        } catch (PlatformUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            RedactedError::report($exception);
        }
    }

    /**
     * @param  array<string, array{account: SocialAccount, events: list<array{read: Closure(SocialAccount): InboxWebhookEvents, at: CarbonImmutable}>}>  $routed
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  Closure(SocialAccount): InboxWebhookEvents  $events
     */
    private function route(array &$routed, Collection $accounts, Closure $events, CarbonImmutable $observedAt): void
    {
        foreach ($accounts as $account) {
            $routed[$account->id] ??= ['account' => $account, 'events' => []];
            $routed[$account->id]['events'][] = ['read' => $events, 'at' => $observedAt];
        }
    }

    private function threadsObservedAt(): CarbonImmutable
    {
        $time = data_get($this->payload, 'time');

        return is_numeric($time) ? CarbonImmutable::createFromTimestamp((int) $time) : CarbonImmutable::now();
    }

    /**
     * The channels a `messaging` entry is about: the Page for Messenger, the
     * Instagram account for both Instagram connection methods.
     *
     * @return Collection<int, SocialAccount>
     */
    private function messagingAccounts(string $object, string $entryId): Collection
    {
        return match ($object) {
            'page' => $this->accounts(fn (Builder $query) => $query->where('platform', Platform::Facebook)->where('platform_user_id', $entryId)),
            'instagram' => $this->accounts(fn (Builder $query) => $query->where(fn (Builder $identity) => $identity
                ->where(fn (Builder $viaFacebook) => $viaFacebook->where('platform', Platform::InstagramFacebook)->where('platform_user_id', $entryId))
                ->orWhere(fn (Builder $direct) => $direct->where('platform', Platform::Instagram)->where('meta->ig_user_id', $entryId)))),
            default => new Collection,
        };
    }

    /**
     * @param  Closure(Builder<SocialAccount>): mixed  $identity
     * @return Collection<int, SocialAccount>
     */
    private function accounts(Closure $identity): Collection
    {
        $query = SocialAccount::query()->connected()->with('workspace.account');
        $identity($query);

        return $query->get()->filter(fn (SocialAccount $account): bool => $account->platform->inboxEnabled()
            && $account->hasInboxAccess()
            && (bool) $account->workspace?->account?->hasAppAccess())->values();
    }

    /**
     * The channels keeping a copy of a message: a reply deleted by its author
     * is gone for every channel that stored it, the author's own (where it is
     * the reply sent) and the one it answered (where it is a comment or a
     * mention), so each is told even though Meta tells only the author.
     *
     * @return Collection<int, SocialAccount>
     */
    private function accountsStoring(Platform $platform, string $platformMessageId): Collection
    {
        $hash = InboxConversation::hashOf($platformMessageId);

        return $this->accounts(fn (Builder $query) => $query
            ->where('platform', $platform)
            ->where(fn (Builder $stored) => $stored
                ->whereIn('id', InboxMessage::query()->where('platform_message_hash', $hash)->select('social_account_id'))
                ->orWhereIn('id', InboxPendingMessage::query()->where('platform_message_hash', $hash)->select('social_account_id'))));
    }

    /**
     * Threads sends `values` as one object; a list of them is accepted too.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private static function threadsValues(array $payload, string $field = 'replies'): array
    {
        $values = data_get($payload, 'values');

        if (! is_array($values)) {
            return [];
        }

        $values = array_is_list($values) ? $values : [$values];

        return array_values(array_map(
            fn (array $item): array => (array) data_get($item, 'value', []),
            array_filter($values, fn (mixed $item): bool => is_array($item) && data_get($item, 'field') === $field),
        ));
    }
}
