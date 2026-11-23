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
     * @param  array<string, mixed>|null  $context
     */
    public static function hasPublishedRoot(?array $context): bool
    {
        $root = self::publishedSegmentAt($context, 0);

        return $root !== null && filled($root['hash']);
    }

    /**
     * The failure message of a thread that stopped with part of it live.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function failureMessage(Post $post, string $message, ?array $context): string
    {
        $published = self::publishedSegments($context)->count();
        $total = 1 + count(ThreadReplies::of($post->meta));

        if ($published > 0 && $published < $total) {
            return __('posts.errors.thread_incomplete', [
                'published' => $published,
                'total' => $total,
                'error' => $message,
            ]);
        }

        return $message;
    }

    /**
     * Keep the root already live, even if its text would now hash differently.
     * Resume replies only up to the first one that changed.
     *
     * @param  array<string, mixed>|null  $context
     * @param  list<string>  $replyHashes
     * @return list<array{hash: string, id: string, ...}>
     */
    public static function resumable(?array $context, array $replyHashes): array
    {
        return self::publishedSegments($context)
            ->takeWhile(fn (array $segment, int $position): bool => $position === 0
                || $segment['hash'] === ($replyHashes[$position - 1] ?? null))
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
        $published = collect();

        while (($segment = self::publishedSegmentAt($context, $published->count())) !== null) {
            $published->push($segment);
        }

        return $published;
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @return array{hash: string, id: string, ...}|null
     */
    private static function publishedSegmentAt(?array $context, int $index): ?array
    {
        $segment = $context[self::KEY][$index] ?? null;

        if (is_string($segment['hash'] ?? null) && is_string($segment['id'] ?? null)) {
            return $segment;
        }

        return null;
    }
}
