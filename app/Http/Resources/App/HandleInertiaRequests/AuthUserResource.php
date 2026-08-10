<?php

declare(strict_types=1);

namespace App\Http\Resources\App\HandleInertiaRequests;

use App\Models\User;

class AuthUserResource
{
    /**
     * @return array<string, mixed>
     */
    public static function make(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->firstName(),
            'email' => $user->email,
            'has_photo' => $user->has_photo,
            'photo_url' => $user->photo_url,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'current_workspace_id' => $user->current_workspace_id,
            'created_at' => $user->created_at->toIso8601String(),
            'updated_at' => $user->updated_at->toIso8601String(),
        ];
    }
}
