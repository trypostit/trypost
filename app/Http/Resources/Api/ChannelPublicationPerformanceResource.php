<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelPublicationPerformanceResource extends JsonResource
{
    /**
     * @return array{id: string, rank: int, post_id: ?string, excerpt: ?string, thumbnail_url: ?string, media: ?array<string, mixed>, permalink: ?string, published_at: string, content_type: ?string, metrics: array<string, int|float|null>}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => data_get($this->resource, 'id'),
            'rank' => data_get($this->resource, 'rank'),
            'post_id' => data_get($this->resource, 'post_id'),
            'excerpt' => data_get($this->resource, 'excerpt'),
            'thumbnail_url' => data_get($this->resource, 'thumbnail_url'),
            'media' => data_get($this->resource, 'media'),
            'permalink' => data_get($this->resource, 'permalink'),
            'published_at' => data_get($this->resource, 'published_at'),
            'content_type' => data_get($this->resource, 'content_type'),
            'metrics' => data_get($this->resource, 'metrics'),
        ];
    }
}
