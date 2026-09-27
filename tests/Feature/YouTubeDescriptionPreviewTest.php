<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Post\PostPreviewer;

test('youtube description preview includes effective bytes without changing title length', function (mixed $description, string $expected) {
    $post = Post::factory()->create(['content' => 'Title']);
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $post->workspace_id]);
    PostPlatform::factory()->youtube()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => ['description' => $description],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0];

    expect($preview['description'])->toBe($expected)
        ->and($preview['description_length_bytes'])->toBe(strlen($expected))
        ->and($preview['sanitized_content'])->toBe('Title')
        ->and($preview['sanitized_length'])->toBe(5)
        ->and($preview['max_content_length'])->toBe(100);
})->with([
    'custom description' => ['ação', 'ação'],
    'null description' => [null, 'Title'],
    'empty description' => ['', 'Title'],
    'invalid metadata type' => [['invalid'], 'Title'],
]);

test('other networks do not gain youtube description preview fields', function () {
    $post = Post::factory()->create(['content' => 'Title']);
    PostPlatform::factory()->create(['post_id' => $post->id, 'enabled' => true]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0];

    expect($preview)->not->toHaveKeys(['description', 'description_length_bytes']);
});
