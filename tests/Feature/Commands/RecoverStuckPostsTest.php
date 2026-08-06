<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPublisher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

test('it recovers posts stuck in publishing for over 1 hour', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Publishing,
        'enabled' => true,
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $platform->refresh();
    $post->refresh();

    expect($platform->status)->toBe(PlatformStatus::Failed);
    expect($platform->error_message)->toBe(__('posts.errors.publishing_timed_out'));
    expect($platform->error_context)->toMatchArray([
        'category' => 'timeout',
    ]);
    expect($post->status)->toBe(PostStatus::Failed);
});

test('it does not touch posts publishing for less than 1 hour', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subMinutes(30),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Publishing,
        'enabled' => true,
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $platform->refresh();
    expect($platform->status)->toBe(PlatformStatus::Publishing);
});

test('it marks post as partially published when some platforms succeeded', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    // One succeeded
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Published,
        'enabled' => true,
    ]);

    // One stuck
    $stuckPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::Instagram,
        ])->id,
        'status' => PlatformStatus::Publishing,
        'enabled' => true,
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $stuckPlatform->refresh();
    $post->refresh();

    expect($stuckPlatform->status)->toBe(PlatformStatus::Failed);
    expect($post->status)->toBe(PostStatus::PartiallyPublished);
});

test('it recovers platforms stuck in retrying for over 1 hour', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Retrying,
        'enabled' => true,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $platform->refresh();
    $post->refresh();

    expect($platform->status)->toBe(PlatformStatus::Failed)
        ->and($platform->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($post->status)->toBe(PostStatus::Failed);
});

test('it does not finalize a post while a platform is still actively retrying', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Retrying,
        'enabled' => true,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('social:recover-stuck-posts')
        ->expectsOutput('Recovered 0 stuck posts.')
        ->assertSuccessful();

    $platform->refresh();
    $post->refresh();

    expect($platform->status)->toBe(PlatformStatus::Retrying)
        ->and($post->status)->toBe(PostStatus::Publishing);
});

test('it does not finalize a post while a platform is still actively pending or publishing', function (PlatformStatus $status) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => $status,
        'enabled' => true,
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('social:recover-stuck-posts')
        ->expectsOutput('Recovered 0 stuck posts.')
        ->assertSuccessful();

    $platform->refresh();
    $post->refresh();

    expect($platform->status)->toBe($status)
        ->and($post->status)->toBe(PostStatus::Publishing);
})->with([
    PlatformStatus::Pending,
    PlatformStatus::Publishing,
]);

test('it fails stale platforms but keeps the post publishing when another platform is still retrying', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $stalePlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Publishing,
        'enabled' => true,
        'updated_at' => now()->subHours(2),
    ]);

    $activeRetry = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::Pinterest,
        ])->id,
        'status' => PlatformStatus::Retrying,
        'enabled' => true,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('social:recover-stuck-posts')
        ->expectsOutput('Recovered 0 stuck posts.')
        ->assertSuccessful();

    $stalePlatform->refresh();
    $activeRetry->refresh();
    $post->refresh();

    expect($stalePlatform->status)->toBe(PlatformStatus::Failed)
        ->and($stalePlatform->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($activeRetry->status)->toBe(PlatformStatus::Retrying)
        ->and($post->status)->toBe(PostStatus::Publishing);
});

test('delayed publish job no-ops after recover fails a stuck retrying platform', function () {
    Event::fake();
    Mail::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'updated_at' => now()->subHours(2),
    ]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'status' => PlatformStatus::Retrying,
        'enabled' => true,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $platform->refresh();
    expect($platform->status)->toBe(PlatformStatus::Failed)
        ->and($platform->error_message)->toBe(__('posts.errors.publishing_timed_out'));

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($platform))->handle();

    $platform->refresh();
    $post->refresh();

    expect($platform->status)->toBe(PlatformStatus::Failed)
        ->and($platform->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($post->status)->toBe(PostStatus::Failed);
});
