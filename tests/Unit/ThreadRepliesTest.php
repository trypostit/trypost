<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\ThreadProgress;
use App\Support\ThreadReplies;

test('thread replies are checked per network', function (Platform $platform, array $replies, ?string $field, array $meta = []) {
    $violation = PostPlatformMetaRules::requiredMetaViolation($platform, [...$meta, 'thread_replies' => $replies]);

    expect($violation === null ? null : $violation[0])->toBe($field);
})->with([
    'x replies fit' => [Platform::X, [str_repeat('a', 280)], null],
    'x 280' => [Platform::X, [str_repeat('a', 281)], 'thread_replies.0'],
    'mastodon replies fit' => [Platform::Mastodon, ['second', 'third'], null],
    'bluesky replies fit' => [Platform::Bluesky, [str_repeat('a', 300)], null],
    'bluesky 300' => [Platform::Bluesky, [str_repeat('a', 301)], 'thread_replies.0'],
    'mastodon 500' => [Platform::Mastodon, ['ok', str_repeat('a', 501)], 'thread_replies.1'],
    'mastodon reply plus warning' => [Platform::Mastodon, [str_repeat('a', 495)], 'thread_replies.0', ['spoiler_text' => 'Ten chars!']],
    'blank reply' => [Platform::Mastodon, ['  '], 'thread_replies.0'],
    'blank reply object' => [Platform::X, [['text' => ' ', 'media' => []]], 'thread_replies.0'],
    'media-only reply' => [Platform::X, [['text' => '', 'media' => [['id' => 'image']]]], null],
    'reply object over the limit' => [Platform::X, [['text' => str_repeat('a', 281), 'media' => [['id' => 'image']]]], 'thread_replies.0'],
    'threads cannot chain' => [Platform::Threads, ['second'], 'thread_replies'],
    'linkedin cannot chain' => [Platform::LinkedIn, ['second'], 'thread_replies'],
    'empty list ignored' => [Platform::LinkedIn, [], null],
]);

test('a thread violation comes before the network own required meta', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::Pinterest, ['thread_replies' => ['second']]))
        ->toBe(['thread_replies', __('posts.form.thread.unsupported')]);
});

test('resume keeps the longest matching prefix', function () {
    $stored = [ThreadProgress::KEY => [
        ['hash' => 'h0', 'id' => '1'],
        ['hash' => 'h1', 'id' => '2'],
        ['hash' => 'h2', 'id' => '3'],
    ]];

    expect(ThreadProgress::resumable($stored, ['h0', 'h1', 'h2', 'h3']))->toHaveCount(3)
        ->and(ThreadProgress::resumable($stored, ['h0', 'changed', 'h2']))->toBe([['hash' => 'h0', 'id' => '1']])
        ->and(ThreadProgress::resumable($stored, ['other']))->toBe([])
        ->and(ThreadProgress::resumable(null, ['h0']))->toBe([]);
});

test('thread_replies has a rule so validated() keeps it', function () {
    expect(PostPlatformMetaRules::rules())->toHaveKeys(['meta.thread_replies', 'meta.thread_replies.*'])
        ->and(ThreadReplies::supports(Platform::X))->toBeTrue()
        ->and(ThreadReplies::supports(Platform::Mastodon))->toBeTrue()
        ->and(ThreadReplies::supports(Platform::Bluesky))->toBeTrue()
        ->and(ThreadReplies::supports(Platform::Threads))->toBeFalse();
});

test('a reply publishes as its network post type and takes that type media rules', function () {
    $image = ['id' => 'image', 'type' => 'image', 'mime_type' => 'image/jpeg'];

    expect(ThreadReplies::replyContentType(Platform::X))->toBe(ContentType::XPost)
        ->and(ThreadReplies::replyContentType(Platform::Bluesky))->toBe(ContentType::BlueskyPost)
        ->and(ThreadReplies::replyContentType(Platform::Mastodon))->toBe(ContentType::MastodonPost)
        ->and(ThreadReplies::replyContentType(Platform::Threads))->toBeNull()
        ->and(ThreadReplies::of(['thread_replies' => ['Two', ['text' => 'Three', 'media' => [$image]]]]))->toBe([
            ['text' => 'Two', 'media' => []],
            ['text' => 'Three', 'media' => [$image]],
        ])
        ->and(ThreadReplies::mediaErrors(Platform::X, ['thread_replies' => [['text' => 'Two', 'media' => [$image]]]], null))->toBe([])
        ->and(ThreadReplies::mediaErrors(Platform::X, ['thread_replies' => ['Two', ['text' => 'Three', 'media' => array_fill(0, 5, $image)]]], null))
        ->toBe(['thread_replies.1.media' => __('posts.form.warnings.max_files_exceeded', ['max' => 4, 'current' => 5])]);
});
