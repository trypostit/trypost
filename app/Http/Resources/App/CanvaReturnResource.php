<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CanvaReturnResource extends JsonResource
{
    /**
     * @return array{import_id: ?string, replaces: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'import_id' => data_get($this->resource, 'import_id'),
            'replaces' => data_get($this->resource, 'replaces'),
        ];
    }
}
