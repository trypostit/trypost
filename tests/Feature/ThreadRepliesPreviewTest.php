<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Services\Post\PostPreviewer;
use App\Services\Social\ContentSanitizer;

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
