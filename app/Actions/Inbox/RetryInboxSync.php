<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;

class RetryInboxSync
{
    /**
     * "Try again" on a sync error: the kind is due again, without the error
     * it showed, and its poll is queued now.
     */
    public static function execute(SocialAccount $account, ConversationKind $kind): void
    {
        InboxSyncState::query()
            ->where('social_account_id', $account->id)
            ->where('kind', $kind->value)
            ->update([
                'next_sync_at' => null,
                'last_error' => null,
                'error_category' => null,
                'consecutive_failures' => 0,
            ]);

        SyncInboxAccount::dispatch($account->id, $kind);
    }
}
