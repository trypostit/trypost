<?php

declare(strict_types=1);

namespace App\Enums\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

enum UserStatusFilter: string
{
    case All = 'all';
    case Blocked = 'blocked';

    /**
     * The users this filter keeps.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function apply(Builder $query): Builder
    {
        return match ($this) {
            self::All => $query,
            self::Blocked => $query->whereNotNull('users.blocked_at'),
        };
    }
}
