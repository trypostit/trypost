<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Events\PostStatusUpdated;
use App\Jobs\PublishToSocialPlatform;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPublisher;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([SendNotification::class]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

test('it recovers posts stuck in publishing for over 1 hour', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Publishing,
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $post->refresh();

    expect($post->publish_status)->toBe(PlatformStatus::Failed);
    expect($post->error_message)->toBe(__('posts.errors.publishing_timed_out'));
    expect($post->error_context)->toMatchArray([
        'category' => 'timeout',
    ]);
    expect($post->status)->toBe(PostStatus::Failed);
});

test('it broadcasts the timed out target so open publish pages move the post to sent', function () {
    Event::fake([PostStatusUpdated::class]);

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Publishing,
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    Event::assertDispatched(
        PostStatusUpdated::class,
        fn (PostStatusUpdated $event): bool => $event->post->is($post)
            && $event->post->publish_status === PlatformStatus::Failed,
    );
});

test('it fails a post without a channel left publishing for over 1 hour, notifying once', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Pending,
        'updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();
    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, 1);
});

test('it does not touch posts publishing for less than 1 hour', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Publishing,
        'updated_at' => now()->subMinutes(30),
        'publication_updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Publishing);
});

test('it recovers platforms stuck in retrying for over 1 hour', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $post->refresh();

    expect($post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($post->status)->toBe(PostStatus::Failed);
});

test('it keeps TikTok photo derivatives when recovering a stuck in-flight publish', function () {
    Storage::fake();
    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_publish_id' => 'publish-stuck',
            'tiktok_derivative_paths' => [$path],
        ],
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    Storage::assertExists($path);
    expect($post->fresh()->error_context)->toMatchArray([
        'tiktok_publish_id' => 'publish-stuck',
        'category' => 'timeout',
    ]);
});

test('it prunes TikTok photo derivatives when recovering a stuck retry with no publish_id', function () {
    Storage::fake();
    $path = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($path, 'image');

    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'tiktok_derivative_paths' => [$path],
        ],
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    Storage::assertMissing($path);
    expect($post->fresh()->error_context['category'] ?? null)->toBe('timeout');
});

test('it preserves an Instagram workflow when recovering a stuck retry', function () {
    $workflow = [
        'stage' => 'final_container',
        'container_id' => 'container-stuck',
    ];
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_context' => [
            'instagram_workflow' => $workflow,
            'retry_count' => 40,
        ],
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->fresh()->error_context)->toMatchArray([
            'instagram_workflow' => $workflow,
            'category' => 'timeout',
        ]);
});

test('it does not finalize a post while a platform is still actively retrying', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('social:recover-stuck-posts')
        ->assertSuccessful();

    $post->refresh();

    expect($post->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($post->status)->toBe(PostStatus::Publishing);
});

test('it does not fail a retry still waiting for its limit window', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'retry_at' => now()->addHours(3),
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Retrying)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('it does not finalize a post while a platform is still actively pending or publishing', function (PlatformStatus $status) {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => $status,
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('social:recover-stuck-posts')
        ->assertSuccessful();

    $post->refresh();

    expect($post->publish_status)->toBe($status)
        ->and($post->status)->toBe(PostStatus::Publishing);
})->with([
    PlatformStatus::Pending,
    PlatformStatus::Publishing,
]);

test('it fails a google business review that outlived the review ceiling', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
        'publication_updated_at' => now()->subHours(25),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Rejected)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.review_unconfirmed'))
        ->and($post->fresh()->status)->toBe(PostStatus::Failed);

    Queue::assertPushed(SendNotification::class);
});

test('it prunes the google business jpeg when a publish times out before review', function () {
    Storage::fake();
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Publishing,
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    Storage::assertMissing($path);
    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($post->fresh()->status)->toBe(PostStatus::Failed);
});

test('recover then reconcile on an expired review notifies once and prunes the jpeg', function () {
    Storage::fake();
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->pendingReview()->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
        'publication_updated_at' => now()->subHours(25),
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();
    (new ReconcileGoogleBusinessPost($post->fresh()))->handle();

    Storage::assertMissing($path);
    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Rejected)
        ->and($post->fresh()->error_message)->toBe(__('posts.errors.review_unconfirmed'))
        ->and($post->fresh()->status)->toBe(PostStatus::Failed);
    Queue::assertPushed(SendNotification::class, 1);
});

test('it prunes the google business jpeg when a review outlives the ceiling', function () {
    Storage::fake();
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => now()->subHours(25),
        'updated_at' => now()->subHours(25),
        'publication_updated_at' => now()->subHours(25),
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    Storage::assertMissing($path);
    expect($post->fresh()->publish_status)->toBe(PlatformStatus::Rejected);
});

test('it does not fail a google business post still sitting in review', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::PendingReview,
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('it does not fail a google business review whose submitted_at is still missing', function () {
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => null,
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subHours(25),
        'publication_updated_at' => now()->subHours(25),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::PendingReview)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('delayed publish job no-ops after recover fails a stuck retrying platform', function () {
    Event::fake();
    Mail::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Publishing,
        'publish_status' => PlatformStatus::Retrying,
        'error_message' => __('posts.errors.platform_unavailable'),
        'updated_at' => now()->subHours(2),
        'publication_updated_at' => now()->subHours(2),
    ]);

    $this->artisan('social:recover-stuck-posts')->assertSuccessful();

    $post->refresh();
    expect($post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->error_message)->toBe(__('posts.errors.publishing_timed_out'));

    $publisher = Mockery::mock(LinkedInPublisher::class);
    $publisher->shouldNotReceive('publish');
    $this->app->instance(LinkedInPublisher::class, $publisher);

    (new PublishToSocialPlatform($post->fresh()))->handle();

    $post->refresh();

    expect($post->publish_status)->toBe(PlatformStatus::Failed)
        ->and($post->error_message)->toBe(__('posts.errors.publishing_timed_out'))
        ->and($post->status)->toBe(PostStatus::Failed);
});
