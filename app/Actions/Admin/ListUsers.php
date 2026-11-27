<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\UserStatusFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ListUsers
{
    /**
     * Every user, newest first, matched by name or email, optionally only the
     * blocked ones.
     *
     * @return Builder<User>
     */
    public static function execute(?string $search = null, ?UserStatusFilter $status = null): Builder
    {
        return User::query()
            ->with(['account.plan', 'account.subscriptions'])
            ->withCount('workspaces')
            ->when($search, fn (Builder $query, string $term) => $query->where(
                fn (Builder $match) => $match->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%"),
            ))
            ->when($status === UserStatusFilter::Blocked, fn (Builder $query) => $query->whereNotNull('blocked_at'))
            ->latest()
            ->orderByDesc('id');
    }
}
