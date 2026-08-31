<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Enums\SocialAccount\Platform;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SocialAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'network' => $this->platform->network(),
            'platform_user_id' => $this->platform_user_id,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'display_label' => $this->display_label,
            'handle_label' => $this->handle_label,
            'avatar_url' => $this->avatar_url,
            'profile_url' => $this->profile_url,
            'status' => $this->status,
            'is_active' => $this->is_active,
            'error_message' => $this->error_message,
            'last_used_at' => $this->last_used_at,
            'created_at' => $this->created_at,
            'google_business_profile_locations' => $this->when(
                $this->platform === Platform::GoogleBusinessProfile,
                fn () => $this->googleBusinessProfileLocations()
                    ->where('is_selected', true)
                    ->orderBy('title')
                    ->get(['id', 'title', 'store_code', 'maps_uri', 'is_verified']),
            ),
        ];
    }
}
