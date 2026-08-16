<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_filename' => $this->original_filename,
            'type' => $this->type->value,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'url' => $this->url,
            'meta' => $this->meta,
            'created_at' => $this->created_at->format('Y-m-d H:i:s'),
        ];
    }
}
