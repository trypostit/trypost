<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Notification\Type;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\User\Locale;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    app()->setLocale(Locale::DEFAULT->value);
});

test('a published post queues the published email for the owner', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => 'inbox',
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->facebook()->scheduled()->create([
        'user_id' => $owner->id,
        'publish_status' => PublishStatus::Published,
        'platform_post_id' => 'fb-1',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($owner, $post) {
        return $job->type === Type::PostPublished
            && $job->user->is($owner)
            && $job->mailable instanceof PostPublished
            && $job->mailable->post->is($post);
    });
});

test('a failed post queues the failed email for the owner', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => 'inbox',
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->forAccount($account)->facebook()->scheduled()->create([
        'user_id' => $owner->id,
        'publish_status' => PublishStatus::Failed,
        'error_message' => 'Failed to publish',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($owner, $post) {
        return $job->type === Type::PostFailed
            && $job->user->is($owner)
            && $job->mailable instanceof PostPublishFailed
            && $job->mailable->post->is($post);
    });
});

test('a publishing post without a destination is failed', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->type === Type::PostFailed);
});

test('a draft without a destination is left alone', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Draft,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNotPushed(SendNotification::class);
});

test('a google business post still in review is not settled', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->googleBusiness()->pendingReview()->create([
        'user_id' => $owner->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('a google business post rejected in review is failed', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->googleBusiness()->publishing()->create([
        'user_id' => $owner->id,
        'publish_status' => PublishStatus::Rejected,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'error_message' => __('posts.errors.rejected_in_review'),
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->type === Type::PostFailed);
});

test('a second settle does not notify again', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->facebook()->publishing()->create([
        'user_id' => $owner->id,
        'publish_status' => PublishStatus::Published,
        'platform_post_id' => 'fb-1',
    ]);

    $finalize = app(FinalizePostPublication::class);
    $finalize->handle($post);
    $finalize->handle($post->fresh());

    expect($post->fresh()->status)->toBe(PostStatus::Published);
    Queue::assertPushedTimes(SendNotification::class, 1);
});

test('an already settled post is left alone', function (PostStatus $status) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->facebook()->create([
        'user_id' => $owner->id,
        'status' => $status,
        'publish_status' => PublishStatus::Published,
        'platform_post_id' => 'fb-1',
        'published_at' => $status === PostStatus::Failed ? null : now(),
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe($status);
    Queue::assertNotPushed(SendNotification::class);
})->with([
    PostStatus::Published,
    PostStatus::Failed,
]);
