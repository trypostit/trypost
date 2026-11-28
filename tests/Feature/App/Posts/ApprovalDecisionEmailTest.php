<?php

declare(strict_types=1);

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Enums\Notification\Type;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\SendNotification;
use App\Mail\PostApproved;
use App\Mail\PostRejected;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');

    $schedule = PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00');
    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'posting_schedule' => $schedule]);
    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'posting_schedule' => $schedule]);
});

/**
 * @return Collection<int, Post>
 */
function approvalDecisionRequest(object $test): Collection
{
    return CreatePosts::execute($test->workspace, $test->requester, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Two channels',
        'media' => [],
        'destinations' => [
            ['social_account_id' => $test->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []],
            ['social_account_id' => $test->x->id, 'content_type' => ContentType::XPost->value, 'meta' => []],
        ],
    ]);
}

test('approving every card of a request sends one email per post', function () {
    $posts = approvalDecisionRequest($this);

    $posts->each(fn (Post $post) => ApprovePost::execute($post, $this->owner));

    $emails = Queue::pushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->mailable instanceof PostApproved);

    expect($emails)->toHaveCount(2)
        ->and($emails->every(fn (SendNotification $notification): bool => $notification->type === Type::Collaboration && $notification->user->is($this->requester)))->toBeTrue()
        ->and($emails->map(fn (SendNotification $notification): string => $notification->mailable->postId)->sort()->values()->all())
        ->toBe($posts->pluck('id')->sort()->values()->all());
});

test('rejecting sends the rejected email to the author', function () {
    $post = approvalDecisionRequest($this)->first();

    RejectPost::execute($post, $this->owner);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->mailable instanceof PostRejected
        && $notification->user->is($this->requester)
        && $notification->mailable->postId === $post->id);
});

test('deciding your own post sends no decision email', function () {
    $post = approvalDecisionRequest($this)->first();
    $post->update(['user_id' => $this->owner->id, 'approval_requested_by' => $this->owner->id]);

    RejectPost::execute($post, $this->owner);

    Queue::assertNotPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->mailable instanceof PostRejected);
});

test('a decision email whose post was deleted is not sent', function () {
    Mail::fake();
    $post = approvalDecisionRequest($this)->first();
    $notification = new SendNotification($this->requester, Type::Collaboration, new PostApproved($post->id, $this->owner, $this->requester));

    $post->delete();
    $notification->handle();

    Mail::assertNothingSent();
});

/**
 * The owner's own approved post, edited back into approval by the requester.
 */
function approvalDecisionEditedByRequester(object $test): Post
{
    $post = CreatePosts::execute($test->workspace, $test->owner, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Owner post',
        'media' => [],
        'destinations' => [
            ['social_account_id' => $test->linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []],
        ],
    ])->sole();

    UpdatePost::execute($test->workspace, $post, ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Typo fixed'], $test->requester);

    return $post->refresh();
}

test('approving a post someone else edited emails the member who asked, not the author', function () {
    $post = approvalDecisionEditedByRequester($this);

    ApprovePost::execute($post, $this->owner);

    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->mailable instanceof PostApproved
        && $notification->user->is($this->requester));
});

test('rejecting a post someone else edited emails the member who asked, not the author', function () {
    $post = approvalDecisionEditedByRequester($this);

    RejectPost::execute($post, workspaceMember($this->workspace, 'admin'));

    Queue::assertPushed(SendNotification::class, fn (SendNotification $notification): bool => $notification->mailable instanceof PostRejected
        && $notification->user->is($this->requester));
});

test('rejecting a post another approver already approved fails and sends no email', function () {
    $post = approvalDecisionRequest($this)->first();
    $stale = Post::query()->findOrFail($post->id);

    ApprovePost::execute($post, $this->owner);
    Queue::fake();

    expect(fn () => RejectPost::execute($stale, workspaceMember($this->workspace, 'admin')))
        ->toThrow(ValidationException::class, __('posts.approvals.errors.not_pending'));

    expect($post->refresh()->status)->toBe(PostStatus::Scheduled);
    Queue::assertNotPushed(SendNotification::class);
});

test('saving a pending post in the composer after it was rejected fails and sends no approval', function () {
    $post = approvalDecisionRequest($this)->first();
    $stale = Post::query()->findOrFail($post->id);

    RejectPost::execute($post, $this->owner);
    Queue::fake();

    expect(fn () => UpdatePost::execute($this->workspace, $stale, ['status' => 'scheduled', 'queue' => 'next'], workspaceMember($this->workspace, 'admin')))
        ->toThrow(ValidationException::class, __('posts.approvals.errors.not_pending'));

    expect($post->refresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNotPushed(SendNotification::class);
});
