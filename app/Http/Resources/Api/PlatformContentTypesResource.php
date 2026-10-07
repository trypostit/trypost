<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Describes a single SocialPlatform with its valid content_types and
 * publishing constraints (length budgets, allowed media types, etc.).
 *
 * `JsonResource::collection(Platform::cases())` produces the full index.
 */
class PlatformContentTypesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Platform $platform */
        $platform = $this->resource;

        return [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'max_content_length' => $platform->maxContentLength(),
            'max_hashtags' => $platform->maxHashtags(),
            'alt_text_max_length' => $platform->altTextMaxLength(),
            'required_meta' => PostPlatformMetaRules::requiredMetaKeys($platform),
            'recommended_content_length' => $platform->recommendedAiContentLength(),
            'allowed_media_types' => array_map(
                fn ($type) => $type->value,
                $platform->allowedMediaTypes(),
            ),
            'default_content_type' => ContentType::defaultFor($platform)->value,
            'content_types' => array_map(
                fn (ContentType $type) => $type->toListingArray(),
                array_values(ContentType::forPlatform($platform)),
            ),
        ];
    }
}
