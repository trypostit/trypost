<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a SocialAccount to expose per-platform publishing configuration.
 */
class PlatformConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform->value,
            'maxContentLength' => $this->resource->maxContentLength(),
            'maxHashtags' => $this->platform->maxHashtags(),
            'maxImages' => $this->platform->maxImages(),
            'allowedMediaTypes' => array_map(fn ($type) => $type->value, $this->platform->allowedMediaTypes()),
            'supportsTextOnly' => $this->platform->supportsTextOnly(),
            'requiresContent' => $this->platform->requiresContent(),
            'publishConfig' => $this->platform->publishConfig(),
        ];
    }
}
