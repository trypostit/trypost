<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
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

test('a post with no enabled targets is left alone', function () {
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

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertNotPushed(SendNotification::class);
});
