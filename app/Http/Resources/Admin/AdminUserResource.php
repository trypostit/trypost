<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'photo_url' => $this->photo_url,
            'email_verified' => filled($this->email_verified_at),
            'is_backoffice_admin' => $this->isBackofficeAdmin(),
            'is_owner' => $this->isAccountOwner(),
            'plan' => $this->account?->plan?->name,
            'has_app_access' => $this->account?->hasAppAccess() === true,
            'workspaces_count' => $this->whenCounted('workspaces'),
            'blocked_at' => $this->blocked_at?->toIso8601String(),
            'blocked_reason' => $this->blocked_reason,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
