<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InboxMessage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InboxMessagePolicy
{
    public function reply(User $user, InboxMessage $message): Response|bool
    {
        return $this->act($user, $message);
    }

    public function resolve(User $user, InboxMessage $message): Response|bool
    {
        return $this->act($user, $message);
    }

    private function act(User $user, InboxMessage $message): Response|bool
    {
        $conversation = $message->conversation;
        $view = (new InboxConversationPolicy)->view($user, $conversation);

        if ($view !== true) {
            return $view;
        }

        return $user->canPublishDirectlyIn($conversation->workspace);
    }
}
