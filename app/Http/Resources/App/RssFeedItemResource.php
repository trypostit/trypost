<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\RssFeedItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RssFeedItem
 */
class RssFeedItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'excerpt' => $this->excerpt,
            'image_url' => $this->image_url,
            'published_at' => $this->published_at->toISOString(),
            'feed' => [
                'id' => $this->feed->id,
                'display_title' => $this->feed->display_title,
                'icon_url' => $this->feed->icon_url,
            ],
        ];
    }
}
