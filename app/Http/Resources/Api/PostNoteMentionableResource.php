<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member a note may mention, written in the body as `@[user:id]`, with
 * what the mention card shows.
 *
 * @mixin User
 */
class PostNoteMentionableResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, photo_url: string|null, timezone: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo_url' => $this->photo_url,
            'timezone' => $this->timezone,
        ];
    }
}
