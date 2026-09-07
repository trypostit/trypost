<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepurposeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_social_account_id' => $this->source_social_account_id,
            'source_account' => $this->whenLoaded('sourceAccount', fn () => new SocialAccountResource($this->sourceAccount)),
            'source_format' => $this->source_format->value,
            'publish_mode' => $this->publish_mode->value,
            'destinations' => $this->destinations,
            'status' => $this->status->value,
            'paused_reason' => $this->paused_reason?->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'last_polled_at' => $this->last_polled_at?->toIso8601String(),
            'next_poll_at' => $this->next_poll_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'published_items_count' => $this->whenCounted('published_items_count'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
