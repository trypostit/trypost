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

/**
 * A workspace whose owner is a member, as CreateWorkspace leaves it.
 */
function finalizeWorkspaceOf(User $owner): Workspace
{
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));

    return $workspace;
}

test('a published post queues the published email for the owner', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = finalizeWorkspaceOf($owner);
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

test('every member of the workspace gets the email, the owner included', function () {
    $owner = User::factory()->create();
    $workspace = finalizeWorkspaceOf($owner);
    $member = workspaceMember($workspace, 'member');
    $requester = workspaceMember($workspace, 'approval');
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'user_id' => $member->id,
        'publish_status' => PublishStatus::Published,
        'platform_post_id' => 'li-1',
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect(Queue::pushed(SendNotification::class)->map(fn (SendNotification $job): string => $job->user->id)->sort()->values()->all())
        ->toBe(collect([$owner->id, $member->id, $requester->id])->sort()->values()->all());
});

test('a failed post queues the failed email for the owner', function () {
    $owner = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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
    $workspace = finalizeWorkspaceOf($owner);
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

test('a google review whose channel is gone is left to the review ceiling', function () {
    $account = SocialAccount::factory()->googleBusiness()->create();
    $post = Post::factory()->forAccount($account)->pendingReview()->create(['platform_post_id' => 'accounts/1/locations/1/localPosts/9']);
    $post->forceFill(['social_account_id' => null])->save();

    app(FinalizePostPublication::class)->handle($post->fresh());

    expect($post->fresh())
        ->status->toBe(PostStatus::Publishing)
        ->publish_status->toBe(PublishStatus::PendingReview)
        ->platform_post_id->toBe('accounts/1/locations/1/localPosts/9');
});
