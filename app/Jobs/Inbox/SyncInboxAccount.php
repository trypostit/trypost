<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\UpsertInboxMessages;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\SyncError;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Events\InboxUpdated;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Exceptions\Inbox\DirectMessagesUnavailableException;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Inbox\InboxDrivers;
use App\Services\Inbox\PollBudget;
use App\Services\Social\Meta\GraphError;
use App\Support\Analytics\AnalyticsRateLimits;
use App\Support\Inbox\RedactedError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SyncInboxAccount implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 900;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $socialAccountId, public ConversationKind $kind, public bool $backfill = false)
    {
        $this->onQueue('inbox');
    }

    public function uniqueId(): string
    {
        return "{$this->socialAccountId}:{$this->kind->value}";
    }

    public function handle(): void
    {
        $account = SocialAccount::query()->find($this->socialAccountId);
        $driver = $account ? app(InboxDrivers::class)->for($account->platform) : null;

        if (! $account || ! $driver || ! in_array($this->kind, app(InboxDrivers::class)->kindsFor($account), true) || $account->status !== Status::Connected || ! $account->platform->inboxEnabled() || ! $account->hasInboxAccess()) {
            return;
        }

        $state = InboxSyncState::query()->firstOrCreate(['social_account_id' => $account->id, 'kind' => $this->kind]);
        $since = now()->subDays((int) config('trypost.inbox.backfill_days'))->toImmutable();
        $resumesBackfill = $state->backfilled_at === null && filled($state->cursor);

        if ($this->hostGuardSpent($account, $state)) {
            return;
        }

        app(PollBudget::class);

        try {
            $result = $driver->poll($account, $this->kind, $this->backfill && ! $resumesBackfill ? null : $state->cursor, $since);
        } catch (TokenExpiredException $exception) {
            $account->markAsTokenExpired($exception->getMessage());

            return;
        } catch (DirectMessagesUnavailableException) {
            $this->recordMessagesUnavailable($account, $state);

            return;
        } catch (PlatformUnavailableException $exception) {
            $this->recordFailure($state, $exception);

            return;
        } catch (SocialPublishException $exception) {
            $this->recordFailure($state, $exception, self::errorCategory($account, $exception));

            if (! $exception->isLimit()) {
                RedactedError::report($exception);
            }

            return;
        } catch (Throwable $exception) {
            $this->recordFailure($state, $exception, self::errorCategory($account, $exception));
            RedactedError::report($exception);

            return;
        }

        try {
            $touched = UpsertInboxMessages::execute($account, $result->messages, broadcast: false);
        } catch (Throwable $exception) {
            $this->recordFailure($state, $exception);
            RedactedError::report($exception);

            return;
        }

        $state->forceFill([
            'cursor' => $result->cursor,
            'last_synced_at' => now(),
            'next_sync_at' => $result->retryAt ?? ($result->partial ? now() : now()->addMinutes(self::pollMinutes($account, $this->kind, $state))),
            'last_error' => null,
            'error_category' => null,
            'consecutive_failures' => 0,
            'backfilled_at' => $this->backfill && ! $result->partial ? now() : $state->backfilled_at,
        ])->save();

        $touched->each(fn (string $conversationId) => InboxUpdated::dispatch($account->workspace_id, $conversationId));

        if ($this->backfill && ! $result->partial && $driver instanceof ReceivesInboxWebhooks) {
            SubscribeInboxWebhooks::dispatch($account->id);
        }
    }

    /**
     * A sync killed by its timeout keeps nothing it read, so the state records
     * why and backs off like any other failure instead of retrying every minute.
     */
    public function failed(?Throwable $exception): void
    {
        $state = InboxSyncState::query()->where('social_account_id', $this->socialAccountId)->where('kind', $this->kind)->first();

        if ($state && $exception) {
            $this->recordFailure($state, $exception);
        }
    }

    /**
     * Meta webhooks are the realtime source: while a kind received one in
     * the last day, its poll only reconciles (every 6 hours by default);
     * otherwise it polls at the network's fast cadence.
     */
    public static function pollMinutes(SocialAccount $account, ConversationKind $kind = ConversationKind::Comment, ?InboxSyncState $state = null): int
    {
        if ($state?->receivesWebhooks() && app(InboxDrivers::class)->for($account->platform) instanceof ReceivesInboxWebhooks) {
            return (int) config('trypost.inbox.webhook_reconcile_minutes');
        }

        $platform = $account->platform->value;
        $minutes = (int) config("trypost.inbox.poll_minutes.{$platform}", 15);

        return $kind === ConversationKind::DirectMessage
            ? (int) config("trypost.inbox.poll_minutes.direct_message.{$platform}", $minutes)
            : $minutes;
    }

    /**
     * Bluesky hosts and Mastodon instances limit per IP, which inbox polls
     * share with analytics: a poll spends the same per-host counter and, when
     * it is spent, comes back once it frees up.
     */
    private function hostGuardSpent(SocialAccount $account, InboxSyncState $state): bool
    {
        $guard = AnalyticsRateLimits::sharedHostGuard($account);

        if (! $guard) {
            return false;
        }

        if (RateLimiter::tooManyAttempts($guard['key'], $guard['limit']->maxAttempts)) {
            $state->forceFill(['next_sync_at' => now()->addSeconds(max(1, RateLimiter::availableIn($guard['key'])))])->save();

            return true;
        }

        RateLimiter::hit($guard['key'], $guard['limit']->decaySeconds);

        return false;
    }

    /**
     * The network turned the channel's messages off (an owner setting): a
     * known state, checked again at the normal cadence and never reported.
     */
    private function recordMessagesUnavailable(SocialAccount $account, InboxSyncState $state): void
    {
        $state->forceFill([
            'last_error' => InboxMessage::MESSAGES_UNAVAILABLE,
            'error_category' => SyncError::MessagesUnavailable,
            'next_sync_at' => now()->addMinutes(self::pollMinutes($account, $this->kind, $state)),
        ])->save();
    }

    /**
     * A Meta permission error, read by its documented code, means the channel
     * lost a permission: reconnecting is what fixes it.
     */
    private static function errorCategory(SocialAccount $account, Throwable $exception): ?SyncError
    {
        if (! in_array($account->platform, [Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook, Platform::Threads], true)) {
            return null;
        }

        if ($exception instanceof AnalyticsCollectionException) {
            return $exception->category === 'permission' ? SyncError::Permission : null;
        }

        $body = $exception instanceof SocialPublishException ? json_decode((string) $exception->rawResponse, true) : null;

        return GraphError::isPermissionDenied(is_array($body) ? $body : null) ? SyncError::Permission : null;
    }

    private function recordFailure(InboxSyncState $state, Throwable $exception, ?SyncError $category = null): void
    {
        $failures = $state->consecutive_failures + 1;
        $backoff = now()->addMinutes(min(60, 2 ** $failures));
        $retryAt = $exception instanceof SocialPublishException ? $exception->retryAt : null;

        $state->forceFill([
            'consecutive_failures' => $failures,
            'last_error' => RedactedError::message($exception),
            'error_category' => $category,
            'next_sync_at' => $retryAt && $retryAt->greaterThan($backoff) ? $retryAt : $backoff,
        ])->save();
    }
}
