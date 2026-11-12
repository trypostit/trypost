<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

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
     * @return array{id: string, post_id: string, body: string, reactions: list<array{emoji: string, count: int, users: list<array{id: string, name: string}>}>, author: array{id: string, name: string|null, photo_url: string|null}|null, created_at: string, updated_at: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->post_id,
            'body' => $this->body,
            'reactions' => SummarizePostNoteReactions::execute($this->resource),
            'author' => $this->user === null ? null : [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'photo_url' => $this->user->photo_url,
            ],
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}
