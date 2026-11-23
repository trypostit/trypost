<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ContentLimitException;
use App\Exceptions\Social\MissingPermissionException;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\ChannelPermissions;
use App\Support\ThreadReplies;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class PublishContentValidator
{
    public static function validate(Post $post): void
    {
        $account = $post->socialAccount;
        $platform = $account?->platform ?? $post->platform;
        $type = $post->content_type ?? ContentType::forMedia($platform, $post->media ?? []);
        $blockedFeature = ChannelPermissions::featureBlockingPost($account, $post->meta, $type);

        if (filled($blockedFeature)) {
            throw new MissingPermissionException(
                $post->platform,
                ChannelPermissions::reconnectMessage($platform, $blockedFeature),
            );
        }

        self::validateThreadReplies($post, $platform, $type);
        self::validateCaptionLength($post);
    }

    private static function validateThreadReplies(Post $post, Platform $platform, ContentType $type): void
    {
        $violation = ThreadReplies::violation($platform, $type, $post->meta, $post->socialAccount);

        if (filled($violation)) {
            [, $message] = $violation;

            throw new ContentLimitException($post->platform, $message);
        }

        $error = Arr::first(ThreadReplies::pollMediaErrors($post->platform, $post->meta));

        if (filled($error)) {
            throw new ContentLimitException($post->platform, $error);
        }
    }

    /** Measure the text the reader sees after the publisher handles markup, links and entities. */
    private static function validateCaptionLength(Post $post): void
    {
        if ($post->content_type?->isCaptionless()) {
            return;
        }

        $text = app(ContentSanitizer::class)->displayText($post->content ?? '', $post->platform);
        $length = Str::length($text) + $post->platform->reservedLength($post->meta);
        $limit = ContentType::maxContentLengthFor($post->socialAccount, $post->platform, $post->content_type);

        if ($length > $limit) {
            throw ContentLimitException::exceeds($post->platform, $limit, $length);
        }
    }
}
