<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Str;

/**
 * A comment TryPost posts on a post right after it goes live, as the same
 * author (`meta.first_comment`, `{text}`). The one home of its rules: which
 * networks take one, each network's length limit and the scopes an account
 * needs for it. A network offers a first comment or thread replies, never
 * both: where a comment is a reply, the first comment is the first reply.
 *
 * What happened to it is the system key `meta.first_comment_result`, written
 * by the publisher: `{post_id, comment_id}` once it is live, or
 * `{post_id, failed: true}`. It is the checkpoint a retry reads and the record
 * the post keeps, since a failed comment never fails the post.
 */
final class FirstComment
{
    public const string RESULT_KEY = 'first_comment_result';

    /**
     * The longest first comment a network takes, in code points, or null when
     * the network takes none. LinkedIn's Comments API documents no limit; 1250
     * is what a real comment call accepted.
     */
    public static function maxLength(?Platform $platform): ?int
    {
        return match ($platform) {
            Platform::LinkedIn, Platform::LinkedInPage => 1250,
            default => null,
        };
    }

    public static function supports(?Platform $platform): bool
    {
        return self::maxLength($platform) !== null;
    }

    /**
     * The scopes an account needs on top of its publish scopes to comment.
     * LinkedIn's publish scopes already cover comments.
     *
     * @return list<string>
     */
    public static function requiredScopes(Platform $platform): array
    {
        return match ($platform) {
            default => [],
        };
    }

    public static function availableFor(SocialAccount $account): bool
    {
        return self::supports($account->platform)
            && array_diff(self::requiredScopes($account->platform), $account->scopes ?? []) === [];
    }

    /**
     * What the composer needs to offer a first comment on this account, or
     * null when the account cannot post one.
     *
     * @return array{maxLength: int}|null
     */
    public static function limitsFor(SocialAccount $account): ?array
    {
        return self::availableFor($account) ? ['maxLength' => (int) self::maxLength($account->platform)] : null;
    }

    /**
     * The first comment as it is stored, or null when there is no text.
     *
     * @return array{text: string}|null
     */
    public static function normalize(mixed $firstComment): ?array
    {
        $text = is_array($firstComment) ? data_get($firstComment, 'text') : null;

        if (! is_string($text) || blank($text)) {
            return null;
        }

        return ['text' => Str::trim($text)];
    }

    public static function text(mixed $meta): ?string
    {
        return data_get(self::normalize(data_get($meta, 'first_comment')), 'text');
    }

    /**
     * A first comment longer than the network takes, checked once the post is
     * scheduled or published, like the post's own text.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function violation(Platform|SocialAccount|null $target, mixed $meta): ?array
    {
        $platform = $target instanceof SocialAccount ? $target->platform : $target;
        $text = self::text($meta);
        $maxLength = self::maxLength($platform);

        if ($text === null || $maxLength === null || mb_strlen($text) <= $maxLength) {
            return null;
        }

        return ['first_comment.text', __('posts.form.first_comment.too_long', ['max' => $maxLength])];
    }
}
