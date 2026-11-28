<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostApproval;
use App\Support\PostingSchedule;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');
    $this->channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(4, '09:00'),
    ]);
    $this->ownerHeaders = ['Authorization' => 'Bearer '.passportToken($this->owner, $this->workspace)];
    $this->requesterHeaders = ['Authorization' => 'Bearer '.passportToken($this->requester, $this->workspace)];
});

function approvalApiRequest(object $test): Post
{
    return CreatePosts::execute($test->workspace, $test->requester, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'API approval',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $test->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
}

test('an approver approves and rejects through the api', function () {
    $approved = approvalApiRequest($this);
    $rejected = approvalApiRequest($this);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $approved))
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Scheduled->value)
        ->assertJsonPath('schedule_mode', 'queue');

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.reject', $rejected))
        ->assertOk()
        ->assertJsonPath('status', PostStatus::Draft->value);
});

test('a member who needs approval cannot approve through the api', function () {
    $request = approvalApiRequest($this);

    $this->withHeaders($this->requesterHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('approving a post that is not pending is unprocessable', function () {
    $request = approvalApiRequest($this);
    $this->withHeaders($this->ownerHeaders)->postJson(route('api.posts.reject', $request))->assertOk();

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post');
});

test('approving while another approval holds the post is a conflict', function () {
    $request = approvalApiRequest($this);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.approve', $request))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('posts.errors.queue_busy'));

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('rejecting while another approval holds the post is a conflict', function () {
    $request = approvalApiRequest($this);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    $this->withHeaders($this->ownerHeaders)
        ->postJson(route('api.posts.reject', $request))
        ->assertStatus(Response::HTTP_CONFLICT)
        ->assertJsonPath('message', __('posts.errors.queue_busy'));

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});
