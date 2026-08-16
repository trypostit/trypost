<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssetResource extends JsonResource
{
    /**
     * @var array{preview_url: string, expires_at: string, preview_mode: string}|null
     */
    private ?array $preview = null;

    /**
     * @param  array{preview_url: string, expires_at: string, preview_mode: string}  $preview
     */
    public function withPreview(array $preview): static
    {
        $this->preview = $preview;

        return $this;
    }

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
            'meta' => $this->meta,
            'created_at' => $this->created_at?->toISOString(),
            ...($this->preview ?? []),
        ];
    }
}
