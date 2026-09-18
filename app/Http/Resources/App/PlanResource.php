<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug->value,
            'name' => $this->name,
            'workspace_limit' => $this->workspace_limit,
        ];
    }
}
