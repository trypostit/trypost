<?php

declare(strict_types=1);

use App\Enums\Post\Origin;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\PostPlatform;

test('a post defaults to the trypost origin', function () {
    $post = Post::factory()->create();

    expect($post->fresh()->origin)->toBe(Origin::TryPost)
        ->and(Origin::DEFAULT)->toBe(Origin::TryPost);
});

test('the origin scopes separate imported posts from trypost posts', function () {
    $tryPost = Post::factory()->create();
    $imported = Post::factory()->imported()->create();

    expect(Post::query()->createdInTryPost()->pluck('id')->all())->toBe([$tryPost->id])
        ->and(Post::query()->imported()->pluck('id')->all())->toBe([$imported->id])
        ->and($imported->fresh()->user_id)->toBeNull();
});

test('a permalink longer than 255 characters is stored whole', function () {
    $url = 'https://www.facebook.com/permalink.php?story_fbid='.str_repeat('9', 600);
    $platform = PostPlatform::factory()->published()->create(['platform_url' => $url]);

    expect($platform->fresh()->platform_url)->toBe($url);
});

test('a publication records when its imported post was dismissed', function () {
    $publication = AnalyticsPublication::factory()->create(['post_dismissed_at' => now()]);

    expect($publication->fresh()->post_dismissed_at)->not->toBeNull();
});
