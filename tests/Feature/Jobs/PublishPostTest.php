<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\SendNotification;
use App\Models\Post;
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

    $post = Post::factory()->forAccount($this->socialAccount)->scheduled()->create([
        'user_id' => $this->user->id,
    ]);

    (new PublishPost($post))->handle();

    $post->refresh();
    expect($post->status)->toBe(PostStatus::Publishing);
});

test('publish post dispatches one publish job for the channel of the post', function () {
    Queue::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->scheduled()->create([
        'user_id' => $this->user->id,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertPushed(PublishToSocialPlatform::class, 1);
    Queue::assertPushed(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->post->is($post),
    );
});

test('publish post dispatches google business posts onto the social publish job', function () {
    Queue::fake();

    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'user_id' => $this->user->id,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertPushed(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->post->is($post),
    );
    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('publish post sends nothing to a network for a post without a channel', function () {
    Queue::fake();

    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    (new PublishPost($post))->handle();

    Queue::assertNotPushed(PublishToSocialPlatform::class);
});

test('publish post failed leaves the post open while the publication is still unfinished', function () {
    Queue::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PublishStatus::Pending,
    ]);

    (new PublishPost($post))->failed(new RuntimeException('queue exploded'));

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('publish post failed finalizes when the publication already finished', function () {
    Queue::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->failed()->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
    ]);

    (new PublishPost($post))->failed(new RuntimeException('queue exploded'));

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class);
});
