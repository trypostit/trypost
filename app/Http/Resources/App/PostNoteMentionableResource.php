<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A member a note may mention, written in the body as `@[name](user:id)`,
 * with what the mention card shows. Built from the workspace's members, so
 * the membership pivot is loaded.
 *
 * @mixin User
 */
class PostNoteMentionableResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, photo_url: string|null, timezone: string, is_admin: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo_url' => $this->photo_url,
            'timezone' => $this->timezone,
            'is_admin' => $this->isAdminThrough($this->pivot->pivotParent, $this->pivot),
        ];
    }
}
