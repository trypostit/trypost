<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\User\Locale;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    app()->setLocale(Locale::DEFAULT->value);
});

test('published notification uses the owner locale', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => 'inbox',
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $postPlatform = PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($owner, $post) {
        $locale = $owner->preferredLocale();
        $platforms = 'Facebook Page (@inbox)';

        return $job->type === Type::PostPublished
            && $job->title === __('notifications.post_published.title', [], $locale)
            && $job->body === __('notifications.post_published.body', ['platforms' => $platforms], $locale)
            && data_get($job->data, 'post_id') === $post->id;
    });
});

test('failed notification uses the owner locale', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'username' => 'inbox',
        'display_name' => 'InboxPlacement.io',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $postPlatform = PostPlatform::factory()->facebook()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    Queue::assertPushed(SendNotification::class, function (SendNotification $job) use ($owner, $post) {
        $locale = $owner->preferredLocale();
        $platforms = 'Facebook Page (@inbox)';

        return $job->type === Type::PostFailed
            && $job->title === __('notifications.post_failed.title', [], $locale)
            && $job->body === __('notifications.post_failed.body', ['platforms' => $platforms], $locale)
            && data_get($job->data, 'post_id') === $post->id;
    });
});

test('a publishing post with no enabled targets is failed', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->facebook()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->type === Type::PostFailed);
});

test('a draft with no enabled targets is left alone', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->facebook()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNotPushed(SendNotification::class);
});

test('a google business target still in review does not settle the post', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('a published sibling does not settle the post while google business is in review', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);
    PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});

test('a rejected google business target next to a published sibling is partial', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);
    PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'status' => PostPlatformStatus::Rejected,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'error_message' => __('posts.errors.rejected_in_review'),
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
    Queue::assertPushed(SendNotification::class, fn (SendNotification $job) => $job->type === Type::PostFailed);
});

test('a second settle does not notify again', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => PostStatus::Publishing,
    ]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'enabled' => true,
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
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'status' => $status,
        'published_at' => $status === PostStatus::Failed ? null : now(),
    ]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->facebook()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'enabled' => true,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe($status);
    Queue::assertNotPushed(SendNotification::class);
})->with([
    PostStatus::Published,
    PostStatus::PartiallyPublished,
    PostStatus::Failed,
]);
