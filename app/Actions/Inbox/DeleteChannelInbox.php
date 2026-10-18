<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Actions\Media\DeleteOwnedMedia;
use App\Models\InboxConversation;
use App\Models\InboxPendingMessage;
use App\Models\SocialAccount;

class DeleteChannelInbox
{
    /**
     * Runs inside the caller's transaction: the stored copies of the
     * channel's inbox files go first (files after commit), since their rows
     * keep the messages and conversations from being deleted.
     */
    public static function forAccount(SocialAccount $account): void
    {
        DeleteOwnedMedia::forInboxChannel($account);

        InboxConversation::query()->where('social_account_id', $account->id)->delete();
        InboxPendingMessage::query()->where('social_account_id', $account->id)->delete();
    }
}
