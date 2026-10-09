<?php

declare(strict_types=1);

use App\Models\Post;
use App\Services\Post\PostPreviewer;

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

test('networks that cannot chain get no thread preview', function () {
    $post = Post::factory()->threads()->create([
        'content' => 'Root',
        'meta' => ['thread_replies' => ['Two']],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh()))->not->toHaveKey('thread_replies');
});
