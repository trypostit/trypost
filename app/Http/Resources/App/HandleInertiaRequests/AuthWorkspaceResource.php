<?php

declare(strict_types=1);

namespace App\Http\Resources\App\HandleInertiaRequests;

use App\Models\User;
use App\Models\Workspace;

class AuthWorkspaceResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Workspace $workspace, ?User $user = null): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'logo_url' => $workspace->logo_url,
            'timezone' => $workspace->timezone,
            'datetime_format' => $workspace->datetime_format,
            'created_at' => $workspace->created_at->toIso8601String(),
            'role' => $user ? self::resolveRole($workspace, $user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(Workspace $workspace): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'logo_url' => $workspace->logo_url,
        ];
    }

    private static function resolveRole(Workspace $workspace, User $user): ?string
    {
        if ($user->isAccountOwner() && $workspace->account_id === $user->account_id) {
            return 'owner';
        }

        return $workspace->members()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot
            ?->role;
    }
}
