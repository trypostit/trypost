<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ArrayType;

/**
 * The `media` input of the post tools, described the same way everywhere. The
 * rules live in `PostMediaRules::rules()`.
 */
trait DescribesPostMedia
{
    protected function mediaSchema(JsonSchema $schema, string $description): ArrayType
    {
        return $schema->array()
            ->items($schema->object(fn (JsonSchema $item): array => [
                'upload_token' => $item->string()->description('Token of a file uploaded earlier with request-media-upload-tool; valid once, before it expires.'),
                'url' => $item->string()->description('Public http(s) URL of an image, video or PDF; downloaded once per call.'),
                'id' => $item->string()->description('UUID of a media item of this workspace; copied for the post (an id already on the post keeps it).'),
                'alt' => $item->string()->description('Alt text for an image.'),
                'meta' => $item->object()->description('Per-item settings: alt_text, user_tags, cover_offset_ms (video cover frame in milliseconds).'),
            ]))
            ->description("{$description} Each item gives exactly one of upload_token, url or id.");
    }
}
