<?php

declare(strict_types=1);

use App\Console\Commands\ProcessScheduledPosts;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
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
});

test('process scheduled posts dispatches publish job for due posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $duePost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    PostPlatform::factory()->create([
        'post_id' => $duePost->id,
        'social_account_id' => $socialAccount->id,
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, function ($job) use ($duePost) {
        return $job->post->id === $duePost->id;
    });
});

test('process scheduled posts does not dispatch for future posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $futurePost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    PostPlatform::factory()->create([
        'post_id' => $futurePost->id,
        'social_account_id' => $socialAccount->id,
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
});

test('process scheduled posts does not dispatch for draft posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $draftPost = Post::factory()->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $draftPost->id,
        'social_account_id' => $socialAccount->id,
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
});

test('process scheduled posts handles multiple due posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $posts = Post::factory()->count(3)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    foreach ($posts as $post) {
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $socialAccount->id,
        ]);
    }

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, 3);
});

test('manual mode does not auto-publish and notifies the owner once', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $duePost = Post::factory()->manual()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
        'content' => 'Post this manually, please.',
    ]);

    PostPlatform::factory()->create([
        'post_id' => $duePost->id,
        'social_account_id' => $socialAccount->id,
    ]);

    // First pass: notify, do not publish.
    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
    Queue::assertPushed(SendNotification::class, function ($job) use ($duePost) {
        return $job->user->id === $this->user->id
            && data_get($job->data, 'post_id') === $duePost->id
            && $job->type === Type::PostManualPublishDue;
    });

    // Second pass: already notified, must NOT notify again.
    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(SendNotification::class, 1);
});

test('manual mode stays scheduled after notification', function () {
    Queue::fake();

    $duePost = Post::factory()->manual()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    expect($duePost->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($duePost->fresh()->manual_publish_notified_at)->not->toBeNull();
});

test('manual future post is not notified before its schedule', function () {
    Queue::fake();

    $futurePost = Post::factory()->manual()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(SendNotification::class);
    expect($futurePost->fresh()->manual_publish_notified_at)->toBeNull();
});

test('auto posts publish even when a manual post is due', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Post::factory()->manual()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $dueAuto = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    PostPlatform::factory()->create([
        'post_id' => $dueAuto->id,
        'social_account_id' => $socialAccount->id,
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, function ($job) use ($dueAuto) {
        return $job->post->id === $dueAuto->id;
    });
});
