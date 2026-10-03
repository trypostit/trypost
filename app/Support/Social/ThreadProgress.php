<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Models\PostPlatform;
use App\Support\ThreadReplies;

/**
 * Segments of a thread already live on the network, kept in
 * PostPlatform.error_context so any retry resumes instead of re-posting.
 */
final class ThreadProgress
{
    public const string KEY = 'thread_progress';

    /**
     * @param  list<string>  $mediaIds
     */
    public static function hash(string $text, array $mediaIds = []): string
    {
        return sha1((string) json_encode([$text, $mediaIds]));
    }

    /**
     * The hash of the root already live. The root is the target's identity, so a
     * resume keeps it even when the same text would now hash differently.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function rootHash(?array $context): ?string
    {
        $root = data_get($context, self::KEY.'.0');
        $hash = data_get($root, 'hash');

        return is_string($hash) && is_string(data_get($root, 'id')) ? $hash : null;
    }

    /**
     * The failure message of a thread that stopped with part of it live.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function failureMessage(PostPlatform $postPlatform, string $message, ?array $context): string
    {
        $published = count((array) data_get($context, self::KEY, []));
        $total = 1 + (ThreadReplies::supports($postPlatform->platform) ? count(ThreadReplies::of($postPlatform->meta)) : 0);

        return $published > 0 && $published < $total
            ? __('posts.errors.thread_incomplete', ['published' => $published, 'total' => $total, 'error' => $message])
            : $message;
    }

    /**
     * The stored segments that still match the current ones, in order. The
     * first segment whose text changed ends the resumable part.
     *
     * @param  array<string, mixed>|null  $context
     * @param  list<string>  $hashes
     * @return list<array<string, mixed>>
     */
    public static function resumable(?array $context, array $hashes): array
    {
        $kept = [];

        foreach (array_values((array) data_get($context, self::KEY, [])) as $index => $entry) {
            if (! is_array($entry) || ($hashes[$index] ?? null) !== data_get($entry, 'hash') || ! is_string(data_get($entry, 'id'))) {
                break;
            }

            $kept[] = $entry;
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $posted
     */
    public static function remember(PostPlatform $postPlatform, array $posted): void
    {
        $postPlatform->forceFill([
            'error_context' => [...($postPlatform->error_context ?? []), self::KEY => $posted],
        ])->save();
    }
}
