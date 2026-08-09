<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;

class UserAiDraftChannel
{
    public function join(User $user, User $owner, string $draftId): bool
    {
        return $user->is($owner);
    }
}
