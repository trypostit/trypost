<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostPlatformResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform?->value,
            'content_type' => $this->content_type?->value,
            'meta' => $this->meta,
            'status' => $this->status?->value,
            'enabled' => $this->enabled,
            'platform_url' => $this->platform_url,
            'published_at' => $this->published_at?->format('Y-m-d H:i:s'),
            'submitted_at' => $this->submitted_at?->format('Y-m-d H:i:s'),
            'last_reconciled_at' => $this->last_reconciled_at?->format('Y-m-d H:i:s'),
            'google_business_profile_location_id' => $this->google_business_profile_location_id,
            'error_message' => $this->error_message,
            'connection_issue_code' => $this->connection_issue_code,
            // Display fields fall back to snapshots (platform_name/username/avatar)
            // so deleted accounts still render correctly in the post history.
            'display_name' => $this->display_name,
            'display_username' => $this->display_username,
            'display_avatar' => $this->display_avatar,
            'social_account' => new SocialAccountResource($this->whenLoaded('socialAccount')),
        ];
    }
}
