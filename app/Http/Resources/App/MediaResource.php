<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'path' => $this->path,
            'url' => $this->url,
            'type' => $this->type->value,
            'mime_type' => $this->mime_type,
            'original_filename' => $this->original_filename,
            'size' => $this->size,
            'meta' => $this->meta,
            'upload_token' => $this->upload_token,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
