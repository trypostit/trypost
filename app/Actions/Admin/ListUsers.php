<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Admin\UserStatusFilter;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ListUsers
{
    /**
     * Every user the filter keeps, newest first, matched by name or email
     * when there is a search.
     *
     * @return Builder<User>
     */
    public static function execute(?string $search, UserStatusFilter $status): Builder
    {
        return $status->apply(User::query())
            ->with(['account.plan', 'account.subscriptions'])
            ->withCount('workspaces')
            ->when($search, fn (Builder $query, string $term): Builder => $query->matching($term))
            ->latest()
            ->orderByDesc('id');
    }
}
