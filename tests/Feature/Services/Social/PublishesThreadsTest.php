<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\MastodonPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\PublishesThreads;
use App\Support\Social\ThreadProgress;

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
    $this->postPlatform = PostPlatform::factory()->create([
        'platform' => Platform::Mastodon,
        'content_type' => ContentType::MastodonPost,
        'meta' => ['thread_replies' => ['two', 'three']],
    ]);
});

test('a thread posts root then replies in order, each replying to the previous', function () {
    $calls = [];

    $result = threadPublisher()->run(
        $this->postPlatform,
        function () use (&$calls): array {
            $calls[] = 'root';

            return ['id' => 'r', 'url' => 'https://mastodon.social/@a/r'];
        },
        function (string $text, array $parent, array $root) use (&$calls): array {
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
        threadPublisher()->run($this->postPlatform, fn (): array => ['id' => 'r'], $reply);
        $this->fail('The thread should not finish.');
    } catch (MastodonPublishException $e) {
        expect($e)->toBe($failure);
    }

    expect(collect($this->postPlatform->fresh()->error_context[ThreadProgress::KEY])->pluck('id')->all())->toBe(['r', 'id-two']);

    $roots = 0;
    $result = threadPublisher()->run($this->postPlatform->fresh(), function () use (&$roots): array {
        $roots++;

        return ['id' => 'duplicate'];
    }, $reply);

    expect($roots)->toBe(0)
        ->and($attempt)->toBe(3)
        ->and($result['id'])->toBe('r')
        ->and($result['thread_reply_ids'])->toBe(['id-two', 'id-three']);
});

test('a changed reply is posted again from the first change', function () {
    ThreadProgress::remember($this->postPlatform, [
        ['hash' => ThreadProgress::hash('root'), 'id' => 'r'],
        ['hash' => ThreadProgress::hash('old two'), 'id' => 'old'],
    ]);
    $replies = [];

    $result = threadPublisher()->run($this->postPlatform->fresh(), fn (): array => ['id' => 'duplicate'], function (string $text, array $parent) use (&$replies): array {
        $replies[] = "{$text}>{$parent['id']}";

        return ['id' => "id-{$text}"];
    });

    expect($replies)->toBe(['two>r', 'three>id-two'])
        ->and($result['id'])->toBe('r');
});

test('a stored root is trusted on resume even when its text no longer hashes the same', function () {
    ThreadProgress::remember($this->postPlatform, [
        ['hash' => ThreadProgress::hash('root before a sanitizer change'), 'id' => 'r', 'url' => 'https://mastodon.social/@a/r'],
        ['hash' => ThreadProgress::hash('two'), 'id' => 'id-two'],
    ]);
    $calls = [];

    $result = threadPublisher()->run($this->postPlatform->fresh(), function () use (&$calls): array {
        $calls[] = 'root';

        return ['id' => 'duplicate'];
    }, function (string $text, array $parent) use (&$calls): array {
        $calls[] = "{$text}>{$parent['id']}";

        return ['id' => "id-{$text}"];
    });

    expect($calls)->toBe(['three>id-two'])
        ->and($result)->toBe(['id' => 'r', 'url' => 'https://mastodon.social/@a/r', 'thread_reply_ids' => ['id-two', 'id-three']]);
});

test('a post without replies writes no checkpoint', function () {
    $this->postPlatform->update(['meta' => []]);

    $result = threadPublisher()->run($this->postPlatform, fn (): array => ['id' => 'r', 'url' => null], fn (): array => ['id' => 'never']);

    expect($result)->toBe(['id' => 'r', 'url' => null])
        ->and($this->postPlatform->fresh()->error_context)->toBeNull();
});

test('replies on a network without threads are never posted', function () {
    $this->postPlatform->update(['platform' => Platform::X, 'content_type' => ContentType::XPost]);

    $result = threadPublisher()->run($this->postPlatform->fresh(), fn (): array => ['id' => 'r'], fn (): array => ['id' => 'never']);

    expect($result)->toBe(['id' => 'r', 'url' => null]);
});
