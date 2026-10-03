<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\PostTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostTemplate
 */
class PostTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'emoji' => $this->emoji,
            'title' => $this->title,
            'description' => $this->description,
            'body' => $this->body,
            'visibility' => $this->visibility->value,
            'author' => $this->relationLoaded('user') ? $this->user?->name : null,
            'can_edit' => $user !== null && $this->isEditableBy($user),
            'can_change_visibility' => $user !== null && $this->user_id === $user->id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
