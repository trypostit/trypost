<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\RssFeedCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RssFeedCollection
 */
class RssFeedCollectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'feeds_count' => $this->whenCounted('feeds'),
        ];
    }
}
