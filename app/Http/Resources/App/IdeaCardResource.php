<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Idea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin Idea
 */
class IdeaCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'idea_stage_id' => $this->idea_stage_id,
            'title' => $this->title,
            'excerpt' => Str::substr((string) $this->body, 0, 300),
            'cover' => data_get($this->media, 0),
            'label_ids' => $this->labels->pluck('id')->values(),
        ];
    }
}
