<?php

declare(strict_types=1);

namespace App\Console\Commands\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Inbox\ImportInboxPost;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxPendingMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Inbox\InboxDrivers;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DispatchInboxSync extends Command
{
    protected $signature = 'inbox:dispatch-sync';

    protected $description = 'Queue an inbox sync for every connected channel that is due';

    /**
     * A channel without the inbox permissions (or a kind it lacks the scope
     * for) is looked at again only after this long; connecting it again or a
     * scope change clears the wait (DispatchInboxBackfill, SocialAccountObserver).
     * A workspace without app access is looked at every hour.
     */
    public const int NO_ACCESS_RECHECK_HOURS = 24;

    public function handle(InboxDrivers $drivers): int
    {
        $platforms = Platform::inboxValues();

        if ($platforms === []) {
            return self::SUCCESS;
        }

        SocialAccount::query()
            ->connected()
            ->whereIn('platform', $platforms)
            ->where(function (Builder $query) use ($platforms, $drivers): void {
                $query->whereDoesntHave('inboxSyncStates');

                foreach ($platforms as $platform) {
                    $query->orWhere(fn (Builder $network) => $network
                        ->where('platform', $platform)
                        ->whereHas('inboxSyncStates', fn (Builder $states) => $states->due()->whereIn('kind', $drivers->for(Platform::from($platform))?->kinds() ?? [])));
                }
            })
            ->with(['inboxSyncStates', 'workspace.account'])
            ->chunkById(200, function (Collection $accounts) use ($drivers): void {
                foreach ($accounts as $account) {
                    if (! $account->hasInboxAccess()) {
                        self::postpone($account, $drivers->for($account->platform)?->kinds() ?? [], now()->addHours(self::NO_ACCESS_RECHECK_HOURS));

                        continue;
                    }

                    if (! $account->workspace?->account?->hasAppAccess()) {
                        self::postpone($account, $drivers->for($account->platform)?->kinds() ?? [], now()->addHour());

                        continue;
                    }

                    $kinds = $drivers->kindsFor($account);

                    self::postpone(
                        $account,
                        array_filter($drivers->for($account->platform)?->kinds() ?? [], fn (ConversationKind $kind): bool => ! in_array($kind, $kinds, true)),
                        now()->addHours(self::NO_ACCESS_RECHECK_HOURS),
                    );

                    $driver = $drivers->for($account->platform);

                    $commentState = $account->inboxSyncStates->first(fn (InboxSyncState $state): bool => $state->kind === ConversationKind::Comment);

                    if ($driver instanceof ReceivesInboxWebhooks && filled($commentState?->backfilled_at) && blank($commentState?->webhook_subscribed_at)) {
                        SubscribeInboxWebhooks::dispatch($account->id);
                    }

                    foreach ($kinds as $kind) {
                        $state = $account->inboxSyncStates->first(fn (InboxSyncState $state): bool => $state->kind === $kind);

                        if ($state && filled($state->next_sync_at) && $state->next_sync_at->isFuture()) {
                            continue;
                        }

                        $state = InboxSyncState::query()->updateOrCreate(
                            ['social_account_id' => $account->id, 'kind' => $kind],
                            ['next_sync_at' => now()->addMinutes(SyncInboxAccount::pollMinutes($account, $kind, $state))],
                        );

                        SyncInboxAccount::dispatch($account->id, $kind, $state->backfilled_at === null);
                    }
                }
            });

        self::resumePendingImports();

        return self::SUCCESS;
    }

    /**
     * Comments waiting for their post keep their import queued even when its
     * job was lost: ImportInboxPost is unique per post, so a running one is
     * never doubled.
     */
    private static function resumePendingImports(): void
    {
        InboxPendingMessage::query()
            ->where('created_at', '>=', now()->subDays(InboxPendingMessage::KEEP_DAYS))
            ->select(['social_account_id', 'platform_post_id'])
            ->distinct()
            ->orderBy('social_account_id')
            ->orderBy('platform_post_id')
            ->get()
            ->each(fn (InboxPendingMessage $pending) => ImportInboxPost::dispatch($pending->social_account_id, $pending->platform_post_id));
    }

    /** @param array<int, ConversationKind> $kinds */
    private static function postpone(SocialAccount $account, array $kinds, CarbonInterface $until): void
    {
        foreach ($kinds as $kind) {
            $state = $account->inboxSyncStates->first(fn (InboxSyncState $state): bool => $state->kind === $kind);

            if ($state && filled($state->next_sync_at) && $state->next_sync_at->isFuture()) {
                continue;
            }

            InboxSyncState::query()->updateOrCreate(
                ['social_account_id' => $account->id, 'kind' => $kind],
                ['next_sync_at' => $until],
            );
        }
    }
}
