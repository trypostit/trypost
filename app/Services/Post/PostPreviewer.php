<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Collection;

/**
 * Renders per-platform previews of a post — applies the platform-specific
 * `ContentSanitizer` rules without publishing. Used by the REST API
 * preview endpoint and the MCP `PreviewPostTool` so the rendering rules
 * stay in one place.
 */
class PostPreviewer
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * @return array{
     *     post_id: string,
     *     original_content: string,
     *     original_length: int,
     *     platforms: array<int, array{
     *         post_platform_id: string,
     *         platform: string,
     *         content_type: ?string,
     *         sanitized_content: string,
     *         sanitized_length: int,
     *         max_content_length: int,
     *         truncated: bool
     *     }>
     * }
     */
    public function forPost(Post $post): array
    {
        $original = (string) $post->content;

        return [
            'post_id' => $post->id,
            'original_content' => $original,
            'original_length' => mb_strlen($original),
            'platforms' => $this->platformPreviews($post, $original)->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function platformPreviews(Post $post, string $original): Collection
    {
        return $post->postPlatforms
            ->where('enabled', true)
            ->values()
            ->map(function (PostPlatform $pp) use ($original) {
                $platform = $pp->socialAccount?->platform ?? $pp->platform;
                $sanitized = $this->sanitizer->sanitize($original, $platform);

                return [
                    'post_platform_id' => $pp->id,
                    'platform' => $platform->value,
                    'content_type' => $pp->content_type?->value,
                    'sanitized_content' => $sanitized,
                    'sanitized_length' => mb_strlen($sanitized),
                    'max_content_length' => $platform->maxContentLength(),
                    'truncated' => mb_strlen($sanitized) < mb_strlen($original),
                ];
            });
    }
}
