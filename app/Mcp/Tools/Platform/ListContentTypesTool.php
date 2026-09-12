<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Platform;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the valid content_types per social platform plus their media constraints: max/min media count, requires_media, accept_images/accept_videos/accept_documents, accepts_gif, accepts_mov (false = MP4 only), forbids_mixed_media, max_video_duration_sec (null = no cap), max_image_bytes / max_video_bytes / max_document_bytes, and the platform default_content_type. These caps mirror each network\'s official limits and are enforced by update-post-tool (status "scheduled") and publish-post-tool: a post whose media exceeds the cap of any enabled content_type is rejected with a per-platform error. Drafts are never blocked. Call this before attaching media or choosing a content_type.')]
class ListContentTypesTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $platforms = [];

        foreach (Platform::cases() as $platform) {
            $contentTypes = array_map(
                fn (ContentType $type) => $type->toListingArray(),
                array_values(ContentType::forPlatform($platform)),
            );

            $platforms[] = [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'max_content_length' => $platform->maxContentLength(),
                'recommended_content_length' => $platform->recommendedAiContentLength(),
                'allowed_media_types' => array_map(
                    fn ($type) => $type->value,
                    $platform->allowedMediaTypes(),
                ),
                'default_content_type' => ContentType::defaultFor($platform)->value,
                'content_types' => $contentTypes,
            ];
        }

        return Response::structured(['platforms' => $platforms]);
    }
}
