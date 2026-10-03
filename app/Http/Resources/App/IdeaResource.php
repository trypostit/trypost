<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Idea;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Idea
 */
class IdeaResource extends JsonResource
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
            'body' => $this->body,
            'media' => $this->media ?? [],
            'label_ids' => $this->labels->pluck('id')->values(),
        ];
    }
}
