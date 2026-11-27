<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Activity
 */
class AdminActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'causer_name' => data_get($this->causer, 'name'),
            'causer_email' => data_get($this->causer, 'email'),
            'properties' => $this->properties?->except(['user_agent'])->all() ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
