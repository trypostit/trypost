<?php

declare(strict_types=1);

namespace App\Http\Resources\App\Changelog;

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChangelogEntry
 */
class ChangelogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'tags' => ($this->tags ?? collect())->map(fn (Tag $tag): string => $tag->value)->values()->all(),
            'cover_url' => $this->coverUrl(),
            'published_on' => $this->published_on->toDateString(),
            'excerpt' => $this->excerpt(),
            'body_html' => $this->bodyHtml(),
            'seen' => (bool) ($this->seen ?? false),
        ];
    }
}
