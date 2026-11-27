<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\DatabaseSessions;

class LogoutOtherSessions
{
    /**
     * Ends every session of the user except the one making the request. Only
     * the database session driver keeps them, so any other driver ends none.
     */
    public static function execute(User $user, string $currentSessionId): void
    {
        DatabaseSessions::forget($user, $currentSessionId);
    }
}
