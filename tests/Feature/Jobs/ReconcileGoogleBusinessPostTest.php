<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Jobs\SendNotification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $this->post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->target = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/123456789/locations/987654321/localPosts/999',
        'submitted_at' => now()->subMinutes(10),
    ]);
});

test('a connection failure during review is deferred until the ceiling', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('settling a live review prunes the JPEG derivative', function () {
    Queue::fake([SendNotification::class]);
    Storage::fake();
    $path = GoogleBusinessDerivativeCleaner::pathFor($this->target->id);
    Storage::put($path, 'image');
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'LIVE',
            'searchUrl' => 'https://posts.google.com/999',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    Storage::assertMissing($path);
});

test('a post that went live is published with the search URL Google returned', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'LIVE',
            'searchUrl' => 'https://posts.google.com/999',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($this->target->fresh()->platform_url)->toBe('https://posts.google.com/999')
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});

test('a post Google refused in review is rejected and fails the post', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'REJECTED',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->not->toBe('posts.errors.rejected_in_review')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('a scheduled post is left in review and marked as checked', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'SCHEDULED',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('an unspecified post is left in review and marked as checked', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'LOCAL_POST_STATE_UNSPECIFIED',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('a post still processing is left in review and marked as checked', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'PROCESSING',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('a post that never settles is given up on once the review ceiling passes', function () {
    Queue::fake([SendNotification::class]);
    $this->target->update(['submitted_at' => now()->subHours(ReconcileGoogleBusinessPost::REVIEW_CEILING_HOURS + 1)]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'PROCESSING',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->not->toBe('posts.errors.review_unconfirmed')
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('a recurring post is treated as live', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'name' => 'accounts/123456789/locations/987654321/localPosts/999',
            'state' => 'RECURRING',
            'searchUrl' => 'https://posts.google.com/999',
        ]),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Published);
});

test('a dead token during review is deferred until the ceiling', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'UNAUTHENTICATED', 'message' => 'bad token'],
        ], 401),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('a google 5xx during review is deferred until the ceiling', function () {
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'INTERNAL'],
        ], 503),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull();
});

test('a missing remote post during review fails immediately', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'NOT_FOUND', 'message' => 'gone'],
        ], 404),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->toBe(__('posts.errors.google_business.not_found'))
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('an invalid argument during review fails immediately', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'INVALID_ARGUMENT', 'message' => 'summary too long'],
        ], 400),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->toBe('summary too long')
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('permission denied during review fails immediately', function () {
    Queue::fake([SendNotification::class]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'PERMISSION_DENIED'],
        ], 403),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->toBe(__('posts.errors.google_business.permission_denied'))
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('an exhausted reconcile job defers until the review ceiling', function () {
    (new ReconcileGoogleBusinessPost($this->target))->failed(new RuntimeException('worker died'));

    expect($this->target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('an exhausted reconcile job gives up once the review ceiling passes', function () {
    Queue::fake([SendNotification::class]);
    $this->target->update(['submitted_at' => now()->subHours(ReconcileGoogleBusinessPost::REVIEW_CEILING_HOURS + 1)]);

    (new ReconcileGoogleBusinessPost($this->target))->failed(new RuntimeException('worker died'));

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->target->fresh()->error_message)->toBe(__('posts.errors.review_unconfirmed'))
        ->and($this->target->fresh()->last_reconciled_at)->not->toBeNull()
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});

test('a dead token after the review ceiling gives up', function () {
    Queue::fake([SendNotification::class]);
    $this->target->update(['submitted_at' => now()->subHours(ReconcileGoogleBusinessPost::REVIEW_CEILING_HOURS + 1)]);
    Http::fake([
        config('trypost.platforms.google_business.local_posts_api').'/*' => Http::response([
            'error' => ['status' => 'UNAUTHENTICATED'],
        ], 401),
    ]);

    (new ReconcileGoogleBusinessPost($this->target))->handle();

    expect($this->target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($this->post->fresh()->status)->toBe(PostStatus::Failed);
});
