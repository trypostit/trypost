<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
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

test('publish post dispatches google business targets onto the social publish job', function () {
    Queue::fake();

    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertPushed(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($platform),
    );
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
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

test('publish post does nothing when no platforms enabled', function () {
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
});

test('publish post failed leaves the post open while targets are still unfinished', function () {
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
    ]);

    (new PublishPost($post))->failed(new RuntimeException('queue exploded'));

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('publish post failed finalizes when every target already finished', function () {
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);

    PostPlatform::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    (new PublishPost($post))->failed(new RuntimeException('queue exploded'));

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class);
});
