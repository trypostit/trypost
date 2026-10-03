<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Media|null
 */
class RssFeedItemImageResource extends JsonResource
{
    /**
     * @return array{data: array<string, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return ['data' => $this->resource === null ? null : MediaResource::make($this->resource)->resolve($request)];
    }
}
