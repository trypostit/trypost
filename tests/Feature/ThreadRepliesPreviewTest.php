<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Post\PostPreviewer;

test('previews thread replies with the network sanitizer', function () {
    $post = Post::factory()->create(['content' => 'Root']);
    $account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $post->workspace_id]);
    PostPlatform::factory()->bluesky()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => ['thread_replies' => ['<p>Second</p>']],
    ]);

    $preview = app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0];

    expect($preview['thread_replies'])->toBe(['Second']);
});

test('networks that cannot chain get no thread preview', function () {
    $post = Post::factory()->create(['content' => 'Root']);
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $post->workspace_id]);
    PostPlatform::factory()->threads()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => ['thread_replies' => ['Two']],
    ]);

    expect(app(PostPreviewer::class)->forPost($post->fresh())['platforms'][0])->not->toHaveKey('thread_replies');
});
