<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ContentLimitException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PublishContentValidator;

test('a caption within the publishing limit passes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $user->id,
        'content' => str_repeat('a', 100),
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => PublishContentValidator::validate($post))->not->toThrow(Exception::class);
});

test('a caption exceeding the publishing limit is refused', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $user->id,
        'content' => str_repeat('a', 4000),
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => PublishContentValidator::validate($post))
        ->toThrow(ContentLimitException::class, __('posts.errors.content_too_long', [
            'platform' => Platform::LinkedIn->label(),
            'max' => 3000,
            'provided' => 4000,
        ]));
});
