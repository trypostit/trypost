<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Models\Post;
use App\Support\ThreadReplies;
use Illuminate\Support\Collection;

/**
 * Segments of a thread already live on the network, kept in
 * posts.error_context so any retry resumes instead of re-posting.
 */
final class ThreadProgress
{
    public const string KEY = 'thread_progress';

    /**
     * A reply poll is part of the segment, so changing it is a new segment;
     * a segment without one keeps the hash it always had.
     *
     * @param  list<string>  $mediaIds
     * @param  array<string, mixed>|null  $poll
     */
    public static function hash(string $text, array $mediaIds = [], ?array $poll = null): string
    {
        $segment = [$text, $mediaIds];

        if (filled($poll)) {
            $segment[] = $poll;
        }

        return sha1((string) json_encode($segment));
    }

    /**
     * The hash of the root already live. The root is the target's identity, so a
     * resume keeps it even when the same text would now hash differently.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function rootHash(?array $context): ?string
    {
        return self::publishedSegments($context)->first()['hash'] ?? null;
    }

    /**
     * The failure message of a thread that stopped with part of it live.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function failureMessage(Post $post, string $message, ?array $context): string
    {
        $published = self::publishedSegments($context)->count();

        if ($published === 0) {
            return $message;
        }

        $total = 1 + count(ThreadReplies::of($post->meta));

        if ($published >= $total) {
            return $message;
        }

        return __('posts.errors.thread_incomplete', ['published' => $published, 'total' => $total, 'error' => $message]);
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
        return self::publishedSegments($context)
            ->takeWhile(fn (array $segment, int $index): bool => $segment['hash'] === ($hashes[$index] ?? null))
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $posted
     */
    public static function remember(Post $post, array $posted): void
    {
        $post->writePublication([
            'error_context' => [...($post->error_context ?? []), self::KEY => $posted],
        ]);
    }

    /**
     * The persisted JSON can contain an incomplete entry. Only a continuous
     * run of checkpoints from the root records how far the thread got.
     *
     * @param  array<string, mixed>|null  $context
     * @return Collection<int, array{hash: string, id: string, ...}>
     */
    private static function publishedSegments(?array $context): Collection
    {
        $stored = data_get($context, self::KEY, []);
        $published = collect();

        for ($index = 0; isset($stored[$index]); $index++) {
            $segment = $stored[$index];

            if (! is_string($segment['hash'] ?? null) || ! is_string($segment['id'] ?? null)) {
                break;
            }

            $published->push($segment);
        }

        return $published;
    }
}
