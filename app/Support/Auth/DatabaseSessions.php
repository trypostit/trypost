<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A user's sessions in the sessions table. Only the database session driver
 * keeps them there, so under any other driver a user has none to list or end.
 */
final class DatabaseSessions
{
    /**
     * The user's sessions, most recently active first.
     *
     * @return Collection<int, object{id: string, ip_address: ?string, user_agent: ?string, last_activity: int}>
     */
    public static function of(User $user): Collection
    {
        if (! self::kept()) {
            return collect();
        }

        return self::table($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);
    }

    /**
     * Ends the user's sessions, all of them or all but one.
     */
    public static function forget(User $user, ?string $exceptId = null): void
    {
        if (! self::kept()) {
            return;
        }

        self::table($user)
            ->when($exceptId, fn (Builder $query, string $id): Builder => $query->where('id', '!=', $id))
            ->delete();
    }

    private static function kept(): bool
    {
        return config('session.driver') === 'database';
    }

    private static function table(User $user): Builder
    {
        return DB::table(config()->string('session.table'))->where('user_id', $user->id);
    }
}
