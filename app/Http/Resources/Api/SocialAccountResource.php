<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

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
            'platform' => $this->platform?->value,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'is_active' => $this->is_active,
            'status' => $this->status?->value,
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
