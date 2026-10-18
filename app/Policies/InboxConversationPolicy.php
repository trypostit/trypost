<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InboxConversationPolicy
{
    public function view(User $user, InboxConversation $conversation): Response|bool
    {
        $allowed = $conversation->workspace->hasMember($user)
            && $conversation->isVisible();

        return $allowed ? true : Response::denyAsNotFound();
    }

    /**
     * Sending a direct message: a member who reads the conversation and
     * publishes directly, as for replies.
     */
    public function message(User $user, InboxConversation $conversation): Response|bool
    {
        $view = $this->view($user, $conversation);

        if ($view !== true) {
            return $view;
        }

        if (! $conversation->isFlat()) {
            return Response::denyAsNotFound();
        }

        return $user->canPublishDirectlyIn($conversation->workspace)
            && (bool) $conversation->socialAccount?->hasInboxAccessFor(ConversationKind::DirectMessage);
    }
}
