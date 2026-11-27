<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\RejectPostTool;
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
use Illuminate\Testing\Fluent\AssertableJson;

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
});

function approvalToolRequest(object $test): Post
{
    return CreatePosts::execute($test->workspace, $test->requester, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'MCP approval',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $test->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
}

test('an approver approves and rejects with the mcp tools', function () {
    $approved = approvalToolRequest($this);
    $rejected = approvalToolRequest($this);

    TryPostServer::actingAs($this->owner)->tool(ApprovePostTool::class, ['post_id' => $approved->id])->assertOk();
    TryPostServer::actingAs($this->owner)->tool(RejectPostTool::class, ['post_id' => $rejected->id])->assertOk();

    expect($approved->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($approved->fresh()->approved_by)->toBe($this->owner->id)
        ->and($rejected->fresh()->status)->toBe(PostStatus::Draft);
});

test('members who need approval cannot use the approval tools', function () {
    $request = approvalToolRequest($this);

    TryPostServer::actingAs($this->requester)
        ->tool(ApprovePostTool::class, ['post_id' => $request->id])
        ->assertHasErrors(['This action is unauthorized.']);

    TryPostServer::actingAs($this->requester)
        ->tool(RejectPostTool::class, ['post_id' => $request->id])
        ->assertHasErrors(['This action is unauthorized.']);

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('list posts filters posts waiting for approval', function () {
    approvalToolRequest($this);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->owner->id]);

    TryPostServer::actingAs($this->owner)
        ->tool(ListPostsTool::class, ['status' => PostStatus::PendingApproval->value])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('posts', 1, fn (AssertableJson $post) => $post->where('status', PostStatus::PendingApproval->value)->etc())->etc());
});

test('approving a post that is no longer pending returns an error', function () {
    $request = approvalToolRequest($this);
    TryPostServer::actingAs($this->owner)->tool(RejectPostTool::class, ['post_id' => $request->id])->assertOk();

    TryPostServer::actingAs($this->owner)
        ->tool(ApprovePostTool::class, ['post_id' => $request->id])
        ->assertHasErrors([__('posts.approvals.errors.not_pending')]);
});

test('the approval tools do not reach posts of another workspace', function () {
    $foreign = Post::factory()->pendingApproval()->create();

    TryPostServer::actingAs($this->owner)
        ->tool(ApprovePostTool::class, ['post_id' => $foreign->id])
        ->assertHasErrors(['Post not found.']);

    TryPostServer::actingAs($this->owner)
        ->tool(RejectPostTool::class, ['post_id' => $foreign->id])
        ->assertHasErrors(['Post not found.']);

    expect($foreign->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('the approval tools report a busy queue while another approval holds the post', function (string $tool) {
    $request = approvalToolRequest($this);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", PostApproval::LOCK_SECONDS)->andReturn($lock);

    TryPostServer::actingAs($this->owner)
        ->tool($tool, ['post_id' => $request->id])
        ->assertHasErrors([__('posts.errors.queue_busy')]);

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
})->with([
    'approve' => [ApprovePostTool::class],
    'reject' => [RejectPostTool::class],
]);
