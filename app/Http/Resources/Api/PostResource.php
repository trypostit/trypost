<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'media' => $this->media,
            'status' => $this->status?->value,
            'publish_mode' => $this->publish_mode?->value,
            'scheduled_at' => $this->scheduled_at?->format('Y-m-d H:i:s'),
            'published_at' => $this->published_at?->format('Y-m-d H:i:s'),
            'platforms' => PostPlatformResource::collection($this->whenLoaded('postPlatforms')),
            'labels' => LabelResource::collection($this->whenLoaded('labels')),
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
