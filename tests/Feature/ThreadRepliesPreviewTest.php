<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Post\PostPreviewer;
use App\Services\Social\ContentSanitizer;
use App\Support\Social\MastodonPoll;

test('previews thread replies with the network sanitizer', function () {
    $post = Post::factory()->bluesky()->create([
        'content' => 'Root',
        'meta' => ['thread_replies' => ['<p>Second</p>', ['text' => '<p>Third</p>', 'media' => [['id' => 'media-1', 'url' => 'https://cdn.test/one.jpg']]]]],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh());

    expect($preview['thread_replies'])->toEqual([
        ['text' => 'Second', 'media' => []],
        ['text' => 'Third', 'media' => [['id' => 'media-1', 'url' => 'https://cdn.test/one.jpg']]],
    ]);
});

test('a linkedin preview shows its comment with the linkedin sanitizer', function () {
    $post = Post::factory()->linkedin()->create([
        'content' => 'Root',
        'meta' => ['thread_replies' => ['<p>Read more at <strong>example.com</strong></p>']],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['thread_replies'])->toEqual([
        ['text' => app(ContentSanitizer::class)->sanitize('<p>Read more at <strong>example.com</strong></p>', Platform::LinkedIn), 'media' => []],
    ]);
});

test('networks that cannot chain get no thread preview', function () {
    $post = Post::factory()->threads()->create([
        'content' => 'Root',
        'meta' => ['thread_replies' => ['Two']],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh()))->not->toHaveKey('thread_replies');
});

test('an x preview shows the poll of the post and of each reply that has one', function () {
    $post = Post::factory()->x()->create([
        'content' => 'Pick one',
        'content_type' => ContentType::XPoll,
        'meta' => [
            'poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60],
            'thread_replies' => [
                ['text' => 'And on Fridays?', 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120]],
                ['text' => 'Thanks!'],
            ],
        ],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh());

    expect($preview['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => 60])
        ->and($preview['thread_replies'])->toEqual([
            ['text' => 'And on Fridays?', 'media' => [], 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120]],
            ['text' => 'Thanks!', 'media' => []],
        ]);
});

test('a mastodon preview shows its poll with the defaults it publishes with, on the post and a reply', function () {
    $post = Post::factory()->mastodon()->create([
        'content' => 'Pick one',
        'content_type' => ContentType::MastodonPoll,
        'meta' => [
            'poll' => ['options' => ['Yes', 'No'], 'multiple' => true],
            'thread_replies' => [['text' => 'And on Fridays?', 'poll' => ['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120, 'hide_totals' => true]]],
        ],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh());

    expect($preview['poll'])->toEqual(['options' => ['Yes', 'No'], 'duration_minutes' => MastodonPoll::DEFAULT_DURATION, 'multiple' => true, 'hide_totals' => false])
        ->and($preview['thread_replies'][0]['poll'])->toEqual(['options' => ['Tabs', 'Spaces'], 'duration_minutes' => 120, 'multiple' => false, 'hide_totals' => true]);
});

test('a threads preview shows only the choices of its poll', function () {
    $post = Post::factory()->threads()->create([
        'content' => 'Pick one',
        'content_type' => ContentType::ThreadsPoll,
        'meta' => ['poll' => ['options' => ['Yes', 'No'], 'duration_minutes' => 60]],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['poll'])->toEqual(['options' => ['Yes', 'No']]);
});

test('a bluesky reply shows no poll in the preview', function () {
    $post = Post::factory()->bluesky()->create([
        'content' => 'Root',
        'meta' => ['thread_replies' => [['text' => 'Two', 'poll' => ['options' => ['Yes', 'No']]]]],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['thread_replies'])->toEqual([['text' => 'Two', 'media' => []]]);
});
