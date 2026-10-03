<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\IdeaStage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IdeaStage
 */
class IdeaStageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'ideas_count' => $this->whenCounted('ideas'),
        ];
    }
}
