<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Models\InboxConversation;

class ShowInboxConversation
{
    /**
     * The conversation without its messages, which are paged by
     * ListConversationThreads, plus how many of its comments were replied to.
     * A direct message conversation carries its last message, as in the list.
     */
    public static function execute(InboxConversation $conversation): InboxConversation
    {
        $conversation->loadMissing(['socialAccount', 'post:id,origin,user_id,created_at,media,content,platform_url,published_at', 'post.user:id,name']);

        if ($conversation->isFlat()) {
            $conversation->load('latestMessage')->loadExists(InboxConversation::unresolvedInboundCheck());
        }

        $conversation->setAttribute('replied_count', $conversation->exists ? ListConversationThreads::repliedCount($conversation) : 0);

        return $conversation;
    }
}
