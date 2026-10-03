<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\PostPlatform;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * @mixin PostPlatform
 */
class InstagramGridTileResource extends JsonResource
{
    /**
     * The first item is the tile cover; all of them feed the lightbox.
     *
     * @return array{id: string, published_at: mixed, kind: string, items: list<array{url: string, type: string, mime_type: ?string, meta: array<string, mixed>|null}>}
     */
    public function toArray(Request $request): array
    {
        $visuals = $this->post->mediaItems
            ->filter(fn (MediaItem $item): bool => $item->isImage() || $item->isVideo())
            ->values();

        return [
            'id' => $this->post_id,
            'published_at' => $this->published_at,
            'kind' => $this->kind($visuals),
            'items' => $visuals->map(fn (MediaItem $item): array => [
                'url' => $item->url,
                'type' => ($item->isVideo() ? MediaType::Video : MediaType::Image)->value,
                'mime_type' => $item->mime_type,
                'meta' => $item->meta,
            ])->all(),
        ];
    }

    /**
     * Instagram shows a single feed video as a reel, so it gets the reel marker too.
     *
     * @param  Collection<int, MediaItem>  $visuals
     */
    private function kind(Collection $visuals): string
    {
        return match (true) {
            $this->content_type === ContentType::InstagramReel => 'reel',
            $visuals->count() > 1 => 'carousel',
            $visuals->first()?->isVideo() === true => 'reel',
            default => 'single',
        };
    }
}
