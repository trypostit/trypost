<?php

declare(strict_types=1);

namespace App\Services\Post;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\Social\XPoll;
use App\Support\ThreadReplies;
use App\Support\YouTubeDescription;
use App\Support\YouTubeMetadata;

/**
 * Renders the preview of a post for its channel — applies the platform-specific
 * `ContentSanitizer` rules without publishing. Used by the REST API preview
 * endpoint and the MCP `PreviewPostTool` so the rendering rules stay in one
 * place.
 */
class PostPreviewer
{
    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /**
     * Without a channel only the original text is returned.
     *
     * @return array{
     *     post_id: string,
     *     original_content: string,
     *     original_length: int,
     *     platform?: string,
     *     content_type?: ?string,
     *     sanitized_content?: string,
     *     sanitized_length?: int,
     *     max_content_length?: int,
     *     truncated?: bool,
     *     title?: string,
     *     description?: string,
     *     description_length_bytes?: int,
     *     poll?: array{options: list<string>, duration_minutes: int},
     *     thread_replies?: list<array{text: string, media: list<array<string, mixed>>, poll?: array{options: list<string>, duration_minutes: int}}>
     * }
     */
    public function forPost(Post $post): array
    {
        $original = (string) $post->content;

        return [
            'post_id' => $post->id,
            'original_content' => $original,
            'original_length' => mb_strlen($original),
            ...$this->channelPreview($post, $original),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelPreview(Post $post, string $original): array
    {
        $platform = $post->socialAccount?->platform ?? $post->platform;

        if (blank($platform)) {
            return [];
        }

        $sanitized = $this->sanitizer->sanitize($original, $platform);

        $preview = [
            'platform' => $platform->value,
            'content_type' => $post->content_type?->value,
            'sanitized_content' => $sanitized,
            'sanitized_length' => mb_strlen($sanitized),
            'max_content_length' => ContentType::maxContentLengthFor($post->socialAccount, $platform, $post->content_type),
            'truncated' => mb_strlen($sanitized) < mb_strlen($original),
        ];

        if ($platform === Platform::YouTube) {
            $description = YouTubeDescription::resolve($post->meta, $sanitized);
            $preview['title'] = YouTubeMetadata::title($post->meta, $sanitized);
            $preview['description'] = $description;
            $preview['description_length_bytes'] = strlen($description);
        }

        $replies = ThreadReplies::supports($platform) ? ThreadReplies::of($post->meta) : [];

        if ($post->content_type === ContentType::XPoll) {
            $preview['poll'] = XPoll::payload($post->meta);
        }

        if ($replies !== []) {
            $preview['thread_replies'] = array_map(fn (array $reply): array => [
                'text' => $this->sanitizer->sanitize($reply['text'], $platform),
                'media' => $reply['media'],
                ...(ThreadReplies::hasPoll($reply) ? ['poll' => XPoll::payload($reply)] : []),
            ], $replies);
        }

        return $preview;
    }
}
