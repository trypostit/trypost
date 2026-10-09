<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Dto\MediaItem;
use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use App\Support\Social\ThreadProgress;
use App\Support\ThreadReplies;
use Illuminate\Support\Collection;

trait PublishesThreads
{
    /**
     * Publishes the root, then each `meta.thread_replies` entry as a reply to
     * the previous segment, with the media of that reply only, checkpointing
     * every live segment so a retry resumes after the last one instead of
     * posting it again. A failure propagates untouched; the job names the live
     * part (ThreadProgress::failureMessage()).
     *
     * @param  callable(): array{id: string, url?: ?string, uri?: string, cid?: string}  $postRoot
     * @param  callable(string, Collection<int, MediaItem>, array<string, mixed>, array<string, mixed>): array{id: string, url?: ?string, uri?: string, cid?: string}  $postReply
     * @param  (callable(array<string, mixed>): void)|null  $afterThread
     * @return array{id: string, url: ?string, thread_reply_ids?: list<string>}
     */
    protected function publishThread(Post $post, string $rootHash, callable $postRoot, callable $postReply, ?callable $afterThread = null): array
    {
        $replies = array_map(
            fn (array $reply): array => [
                'text' => app(ContentSanitizer::class)->sanitize($reply['text'], $post->platform),
                'media' => ThreadReplies::mediaItems($reply),
            ],
            ThreadReplies::supports($post->platform) ? ThreadReplies::of($post->meta) : [],
        );
        $hashes = [
            ThreadProgress::rootHash($post->error_context) ?? $rootHash,
            ...array_map(fn (array $reply): string => ThreadProgress::hash($reply['text'], $reply['media']->map(fn (MediaItem $item): string => $item->id)->all()), $replies),
        ];
        $posted = ThreadProgress::resumable($post->error_context, $hashes);

        if ($posted === []) {
            $posted[] = self::threadSegment($hashes[0], $postRoot());

            if ($replies !== []) {
                ThreadProgress::remember($post, $posted);
            }
        }

        foreach ($replies as $index => $reply) {
            $position = $index + 1;

            if (isset($posted[$position])) {
                continue;
            }

            $posted[] = self::threadSegment($hashes[$position], $postReply($reply['text'], $reply['media'], $posted[$position - 1], $posted[0]));

            ThreadProgress::remember($post, $posted);
        }

        if ($afterThread !== null) {
            $afterThread($posted[0]);
        }

        $root = ['id' => (string) $posted[0]['id'], 'url' => $posted[0]['url'] ?? null];

        return $replies === []
            ? $root
            : [...$root, 'thread_reply_ids' => array_map(fn (array $segment): string => (string) $segment['id'], array_slice($posted, 1))];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function threadSegment(string $hash, array $result): array
    {
        return ['hash' => $hash, ...$result, 'id' => (string) data_get($result, 'id')];
    }
}
