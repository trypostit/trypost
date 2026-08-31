<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('publish post marks post as publishing', function () {
    Queue::fake();

    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    (new PublishPost($post))->handle();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing);
});

test('publish post is unique per post', function () {
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $job = new PublishPost($post);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe($post->id);
});

test('publish post dispatches publish to social platform for each enabled platform', function () {
    Queue::fake();

    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $platform1 = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $platform2 = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertPushed(PublishToSocialPlatform::class, 2);
});

test('publish post does not dispatch for disabled platforms', function () {
    Queue::fake();

    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    PostPlatform::factory()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
});

test('publish post fails safely when no platforms are enabled', function () {
    Queue::fake();

    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertNotPushed(PublishToSocialPlatform::class);
    expect($post->fresh()->status)->toBe(PostStatus::Failed);
});

test('stale publish job does not fail a post that was returned to draft', function () {
    Queue::fake();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);
    PostPlatform::factory()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    (new PublishPost($post))->handle();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNotPushed(PublishToSocialPlatform::class);
});

test('duplicate publish job does not redispatch a target already under Google review', function () {
    Queue::fake();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
        'status' => PostPlatformStatus::PendingReview,
    ]);

    (new PublishPost($post))->handle();

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(PublishToSocialPlatform::class);
});
