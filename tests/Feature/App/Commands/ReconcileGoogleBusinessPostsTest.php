<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
});

$target = function (array $attributes = []) {
    return Post::factory()->forAccount(test()->account)->pendingReview()->create([
        'user_id' => test()->user->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => now()->subMinutes(30),
        ...$attributes,
    ]);
};

test('it dispatches a reconciliation for every target still awaiting review', function () use ($target) {
    $awaiting = $target();
    $published = $target(['status' => PostStatus::Published, 'publish_status' => PlatformStatus::Published]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertPushed(ReconcileGoogleBusinessPost::class, 1);
    Queue::assertPushed(
        ReconcileGoogleBusinessPost::class,
        fn (ReconcileGoogleBusinessPost $job): bool => $job->post->id === $awaiting->id,
    );
    Queue::assertNotPushed(
        ReconcileGoogleBusinessPost::class,
        fn (ReconcileGoogleBusinessPost $job): bool => $job->post->id === $published->id,
    );
});

test('it leaves a target alone until the reconciliation interval has passed', function () use ($target) {
    $target(['last_reconciled_at' => now()->subSeconds(30)]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertNotPushed(ReconcileGoogleBusinessPost::class);
});

test('it dispatches again once the reconciliation interval has passed', function () use ($target) {
    $awaiting = $target(['last_reconciled_at' => now()->subMinutes(6)]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertPushed(
        ReconcileGoogleBusinessPost::class,
        fn (ReconcileGoogleBusinessPost $job): bool => $job->post->id === $awaiting->id,
    );
});

test('it skips a target that has no remote post id', function () use ($target) {
    $target(['platform_post_id' => null]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertNotPushed(ReconcileGoogleBusinessPost::class);
});
