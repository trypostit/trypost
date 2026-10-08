<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\MastodonPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\PublishesThreads;
use App\Support\Social\ThreadProgress;
use Illuminate\Support\Collection;

function threadPublisher(): object
{
    return new class
    {
        use PublishesThreads;

        public function run(PostPlatform $postPlatform, callable $postRoot, callable $postReply, ?callable $afterThread = null): array
        {
            return $this->publishThread($postPlatform, ThreadProgress::hash('root'), $postRoot, $postReply, $afterThread);
        }
    };
}

beforeEach(function () {
    $this->post = PostPlatform::factory()->create([
        'platform' => Platform::Mastodon,
        'content_type' => ContentType::MastodonPost,
        'meta' => ['thread_replies' => ['two', 'three']],
    ]);
});

test('a thread posts root then replies in order, each replying to the previous', function () {
    $calls = [];

    $result = threadPublisher()->run(
        $this->post,
        function () use (&$calls): array {
            $calls[] = 'root';

            return ['id' => 'r', 'url' => 'https://mastodon.social/@a/r'];
        },
        function (string $text, Collection $media, array $parent, array $root) use (&$calls): array {
            $calls[] = "{$text}>{$parent['id']}>{$root['id']}";

            return ['id' => "id-{$text}"];
        },
        function (array $root) use (&$calls): void {
            $calls[] = "after>{$root['id']}";
        },
    );

    expect($calls)->toBe(['root', 'two>r>r', 'three>id-two>r', 'after>r'])
        ->and($result)->toBe(['id' => 'r', 'url' => 'https://mastodon.social/@a/r', 'thread_reply_ids' => ['id-two', 'id-three']]);
});

test('a failed reply keeps the progress and the retry resumes after it', function () {
    $attempt = 0;
    $failure = new MastodonPublishException(userMessage: 'Rate limited', category: ErrorCategory::RateLimit, platformErrorCode: '429');
    $reply = function (string $text) use (&$attempt, $failure): array {
        $attempt++;

        if ($text === 'three' && $attempt === 2) {
            throw $failure;
        }

        return ['id' => "id-{$text}"];
    };

    try {
        threadPublisher()->run($this->post, fn (): array => ['id' => 'r'], $reply);
        $this->fail('The thread should not finish.');
    } catch (MastodonPublishException $e) {
        expect($e)->toBe($failure);
    }

    expect(collect($this->post->fresh()->error_context[ThreadProgress::KEY])->pluck('id')->all())->toBe(['r', 'id-two']);

    $roots = 0;
    $result = threadPublisher()->run($this->post->fresh(), function () use (&$roots): array {
        $roots++;

        return ['id' => 'duplicate'];
    }, $reply);

    expect($roots)->toBe(0)
        ->and($attempt)->toBe(3)
        ->and($result['id'])->toBe('r')
        ->and($result['thread_reply_ids'])->toBe(['id-two', 'id-three']);
});

test('a changed reply is posted again from the first change', function () {
    ThreadProgress::remember($this->post, [
        ['hash' => ThreadProgress::hash('root'), 'id' => 'r'],
        ['hash' => ThreadProgress::hash('old two'), 'id' => 'old'],
    ]);
    $replies = [];

    $result = threadPublisher()->run($this->post->fresh(), fn (): array => ['id' => 'duplicate'], function (string $text, Collection $media, array $parent) use (&$replies): array {
        $replies[] = "{$text}>{$parent['id']}";

        return ['id' => "id-{$text}"];
    });

    expect($replies)->toBe(['two>r', 'three>id-two'])
        ->and($result['id'])->toBe('r');
});

test('a stored root is trusted on resume even when its text no longer hashes the same', function () {
    ThreadProgress::remember($this->post, [
        ['hash' => ThreadProgress::hash('root before a sanitizer change'), 'id' => 'r', 'url' => 'https://mastodon.social/@a/r'],
        ['hash' => ThreadProgress::hash('two'), 'id' => 'id-two'],
    ]);
    $calls = [];

    $result = threadPublisher()->run($this->post->fresh(), function () use (&$calls): array {
        $calls[] = 'root';

        return ['id' => 'duplicate'];
    }, function (string $text, Collection $media, array $parent) use (&$calls): array {
        $calls[] = "{$text}>{$parent['id']}";

        return ['id' => "id-{$text}"];
    });

    expect($calls)->toBe(['three>id-two'])
        ->and($result)->toBe(['id' => 'r', 'url' => 'https://mastodon.social/@a/r', 'thread_reply_ids' => ['id-two', 'id-three']]);
});

test('a post without replies writes no checkpoint', function () {
    $this->post->update(['meta' => []]);

    $result = threadPublisher()->run($this->post, fn (): array => ['id' => 'r', 'url' => null], fn (): array => ['id' => 'never']);

    expect($result)->toBe(['id' => 'r', 'url' => null])
        ->and($this->post->fresh()->error_context)->toBeNull();
});

test('replies on a network without threads are never posted', function () {
    $this->post->update(['platform' => Platform::Threads, 'content_type' => ContentType::ThreadsPost]);

    $result = threadPublisher()->run($this->post->fresh(), fn (): array => ['id' => 'r'], fn (): array => ['id' => 'never']);

    expect($result)->toBe(['id' => 'r', 'url' => null]);
});

test('each reply gets only its own media, and a changed reply media is posted again', function () {
    $image = ['id' => 'media-1', 'path' => 'medias/one.jpg', 'url' => 'https://cdn.test/one.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'];
    $this->post->update(['meta' => ['thread_replies' => [['text' => 'two', 'media' => [$image]], ['text' => 'three', 'media' => []]]]]);
    ThreadProgress::remember($this->post, [
        ['hash' => ThreadProgress::hash('root'), 'id' => 'r'],
        ['hash' => ThreadProgress::hash('two'), 'id' => 'old'],
    ]);
    $calls = [];

    threadPublisher()->run($this->post->fresh(), fn (): array => ['id' => 'duplicate'], function (string $text, Collection $media, array $parent) use (&$calls): array {
        $calls[] = [$text, $media->pluck('id')->all(), $parent['id']];

        return ['id' => "id-{$text}"];
    });

    expect($calls)->toBe([['two', ['media-1'], 'r'], ['three', [], 'id-two']]);
});
