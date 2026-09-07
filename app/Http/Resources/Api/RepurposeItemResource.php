<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepurposeItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repurpose_id' => $this->repurpose_id,
            'source_media_id' => $this->source_media_id,
            'source_permalink' => $this->source_permalink,
            'source_created_at' => $this->source_created_at?->toIso8601String(),
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            'error' => $this->error,
            'posts' => $this->whenLoaded('posts', fn () => $this->posts->map(fn ($post) => [
                'id' => $post->id,
                // The post carries the truth about publication, not the item:
                // rolling the item status up from here would rewrite history
                // whenever someone edits or deletes a replicated post.
                'platforms' => $post->postPlatforms
                    ->where('enabled', true)
                    ->map(fn ($postPlatform) => [
                        'platform' => $postPlatform->platform?->value,
                        'status' => $postPlatform->status?->value,
                    ])
                    ->filter(fn (array $entry): bool => $entry['platform'] !== null)
                    ->values(),
            ])->values()),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
