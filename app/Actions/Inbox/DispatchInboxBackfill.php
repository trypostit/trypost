<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxDrivers;
use App\Support\Inbox\RedactedError;
use Throwable;

/**
 * A (re)connect reads the last 30 days by polling; the webhook subscription
 * follows once a backfill completes (SyncInboxAccount), so realtime starts
 * from a complete inbox.
 */
class DispatchInboxBackfill
{
    public function handle(SocialAccount $socialAccount): void
    {
        try {
            InboxSyncState::clearWaits($socialAccount->id);

            $channel = SocialAccount::query()->connected()->find($socialAccount->id);

            if (! $channel
                || ! $channel->platform->inboxEnabled()
                || ! $channel->hasInboxAccess()
                || ! $channel->workspace->account->hasAppAccess()) {
                return;
            }

            foreach (app(InboxDrivers::class)->kindsFor($channel) as $kind) {
                SyncInboxAccount::dispatch($channel->id, $kind, true)->afterCommit();
            }
        } catch (Throwable $exception) {
            RedactedError::report($exception);
        }
    }
}
