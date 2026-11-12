<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Actions\PostNote\SummarizePostNoteReactions;
use App\Models\PostNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PostNote
 */
class PostNoteResource extends JsonResource
{
    /**
     * @return array{id: string, body: string, user_id: string, created_at: mixed, updated_at: mixed, reactions: list<array{emoji: string, count: int, users: list<array{id: string, name: string}>}>, user: array{id: string, name: string|null, photo_url: string|null}|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'user_id' => $this->user_id,
            'reactions' => SummarizePostNoteReactions::execute($this->resource),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'photo_url' => $this->user->photo_url,
            ],
        ];
    }
}
