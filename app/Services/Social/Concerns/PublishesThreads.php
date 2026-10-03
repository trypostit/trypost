<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Models\PostPlatform;
use App\Services\Social\ContentSanitizer;
use App\Support\Social\ThreadProgress;
use App\Support\ThreadReplies;

trait PublishesThreads
{
    /**
     * Publishes the root, then each `meta.thread_replies` entry as a reply to
     * the previous segment, checkpointing every live segment so a retry
     * resumes after the last one instead of posting it again. A failure
     * propagates untouched; the job names the live part
     * (ThreadProgress::failureMessage()).
     *
     * @param  callable(): array{id: string, url?: ?string, uri?: string, cid?: string}  $postRoot
     * @param  callable(string, array<string, mixed>, array<string, mixed>): array{id: string, url?: ?string, uri?: string, cid?: string}  $postReply
     * @param  (callable(array<string, mixed>): void)|null  $afterThread
     * @return array{id: string, url: ?string, thread_reply_ids?: list<string>}
     */
    protected function publishThread(PostPlatform $postPlatform, string $rootHash, callable $postRoot, callable $postReply, ?callable $afterThread = null): array
    {
        $replies = array_map(
            fn (string $reply): string => app(ContentSanitizer::class)->sanitize($reply, $postPlatform->platform),
            ThreadReplies::supports($postPlatform->platform) ? ThreadReplies::of($postPlatform->meta) : [],
        );
        $hashes = [ThreadProgress::rootHash($postPlatform->error_context) ?? $rootHash, ...array_map(fn (string $reply): string => ThreadProgress::hash($reply), $replies)];
        $posted = ThreadProgress::resumable($postPlatform->error_context, $hashes);

        if ($posted === []) {
            $posted[] = self::threadSegment($hashes[0], $postRoot());

            if ($replies !== []) {
                ThreadProgress::remember($postPlatform, $posted);
            }
        }

        foreach ($replies as $index => $reply) {
            $position = $index + 1;

            if (isset($posted[$position])) {
                continue;
            }

            $posted[] = self::threadSegment($hashes[$position], $postReply($reply, $posted[$position - 1], $posted[0]));

            ThreadProgress::remember($postPlatform, $posted);
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
