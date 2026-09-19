<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

$target = function (array $attributes = []) {
    return PostPlatform::factory()->googleBusiness()->create([
        'post_id' => test()->post->id,
        'social_account_id' => test()->account->id,
        'status' => PlatformStatus::PendingReview,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
        'submitted_at' => now()->subMinutes(30),
        ...$attributes,
    ]);
};

test('it dispatches a reconciliation for every target still awaiting review', function () use ($target) {
    $awaiting = $target();
    $published = $target(['status' => PlatformStatus::Published]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertPushed(ReconcileGoogleBusinessPost::class, 1);
    Queue::assertPushed(
        ReconcileGoogleBusinessPost::class,
        fn (ReconcileGoogleBusinessPost $job): bool => $job->postPlatform->id === $awaiting->id,
    );
    Queue::assertNotPushed(
        ReconcileGoogleBusinessPost::class,
        fn (ReconcileGoogleBusinessPost $job): bool => $job->postPlatform->id === $published->id,
    );
});

test('it leaves a target alone until the reconciliation interval has passed', function () use ($target) {
    $target(['last_reconciled_at' => now()->subSeconds(30)]);

    $this->artisan('social:reconcile-google-business-posts')->assertSuccessful();

    Queue::assertNotPushed(ReconcileGoogleBusinessPost::class);
});
