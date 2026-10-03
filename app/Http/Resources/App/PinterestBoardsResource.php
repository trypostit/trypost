<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PinterestBoardsResource extends JsonResource
{
    /**
     * @return array{boards: mixed, truncated: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'boards' => PinterestBoardResource::collection(data_get($this->resource, 'boards', [])),
            'truncated' => (bool) data_get($this->resource, 'truncated', false),
        ];
    }
}
