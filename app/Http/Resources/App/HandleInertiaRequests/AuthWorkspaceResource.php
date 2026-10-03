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
            'created_at' => $workspace->created_at->toIso8601String(),
            ...($user?->accessIn($workspace) ?? ['is_owner' => false, 'is_admin' => false, 'requires_approval' => false]),
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
}
