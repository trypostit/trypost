<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ListActiveSessions
{
    /**
     * The user's sessions, most recently active first. Only the database
     * session driver keeps them, so any other driver lists none.
     *
     * @return list<array{id: string, ip_address: string|null, user_agent: string|null, last_active: string, is_current: bool}>
     */
    public static function execute(User $user, string $currentSessionId): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $session): array => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                'is_current' => $session->id === $currentSessionId,
            ])->values()->all();
    }
}
