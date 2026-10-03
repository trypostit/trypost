<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PinterestBoardResource extends JsonResource
{
    /**
     * @return array{id: string, name: string, cover_url: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) data_get($this->resource, 'id'),
            'name' => (string) data_get($this->resource, 'name'),
            'cover_url' => data_get($this->resource, 'cover_url'),
        ];
    }
}
