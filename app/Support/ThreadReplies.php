<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SocialAccount\Platform;
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Str;

/**
 * Text replies that follow a post as a thread (`meta.thread_replies`), on the
 * networks that let an app reply to its own post with the scopes we hold.
 */
class ThreadReplies
{
    public const int MAX_REPLIES = 24;

    public static function supports(?Platform $platform): bool
    {
        return in_array($platform, [Platform::Bluesky, Platform::Mastodon], true);
    }

    /**
     * @return list<string>
     */
    public static function of(mixed $meta): array
    {
        return array_values(array_map(
            fn (mixed $reply): string => is_string($reply) ? $reply : '',
            (array) data_get($meta, 'thread_replies', []),
        ));
    }

    /**
     * Each reply is measured like the post itself: sanitized text plus what the
     * network counts besides it (the Mastodon content warning every reply repeats).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function violation(?Platform $platform, mixed $meta): ?array
    {
        $replies = self::of($meta);

        if ($replies === []) {
            return null;
        }

        if ($platform === null || ! self::supports($platform)) {
            return ['thread_replies', __('posts.form.thread.unsupported')];
        }

        $reserved = $platform->reservedLength(is_array($meta) ? $meta : null);

        foreach ($replies as $index => $reply) {
            if (blank(Str::trim($reply))) {
                return ["thread_replies.{$index}", __('posts.form.thread.reply_empty')];
            }

            $over = $platform->contentOverflow(app(ContentSanitizer::class)->displayText($reply, $platform), $reserved);

            if ($over > 0) {
                return ["thread_replies.{$index}", __('posts.form.thread.reply_too_long', ['limit' => $platform->maxContentLength() - $reserved, 'over' => $over])];
            }
        }

        return null;
    }
}
