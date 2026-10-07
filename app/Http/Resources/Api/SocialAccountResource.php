<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

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
            'status' => $this->status?->value,
            'has_posting_schedule' => $this->hasPostingSchedule(),
            'timezone' => $this->timezone,
            'posting_goal' => $this->posting_goal,
            'max_content_length' => $this->maxContentLength(),
            'long_posts' => $this->hasXLongPosts(),
            'verified_badge' => $this->verified_badge,
        ];
    }
}
