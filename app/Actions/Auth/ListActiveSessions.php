<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\Auth\DatabaseSessions;
use Carbon\Carbon;

class ListActiveSessions
{
    /**
     * The user's sessions, most recently active first. Only the database
     * session driver keeps them, so any other driver lists none.
     *
     * @return list<array{id: string, user_agent: string|null, last_active: string, is_current: bool}>
     */
    public static function execute(User $user, string $currentSessionId): array
    {
        return DatabaseSessions::of($user)
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'user_agent' => $session->user_agent,
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'is_current' => $session->id === $currentSessionId,
            ])->values()->all();
    }
}
