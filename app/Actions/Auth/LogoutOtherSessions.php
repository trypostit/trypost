<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class LogoutOtherSessions
{
    /**
     * Ends every session of the user except the one making the request.
     */
    public static function execute(User $user, string $currentSessionId): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
    }
}
