<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\RssFeed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RssFeed
 */
class RssFeedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'display_title' => $this->display_title,
            'url' => $this->url,
            'site_url' => $this->site_url,
            'icon_url' => $this->icon_url,
            'rss_feed_collection_id' => $this->rss_feed_collection_id,
            'last_succeeded_at' => $this->last_succeeded_at?->toISOString(),
            'last_error' => $this->last_error,
            'consecutive_failures' => $this->consecutive_failures,
        ];
    }
}
