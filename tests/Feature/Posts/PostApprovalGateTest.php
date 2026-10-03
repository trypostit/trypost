<?php

declare(strict_types=1);

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'America/Sao_Paulo'));

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->owner->account_id, 'user_id' => $this->owner->id]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->requester = workspaceMember($this->workspace, 'approval');

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')->withTime(5, '09:00'),
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function approvalGatePayload(SocialAccount $channel, array $overrides = []): array
{
    return [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Needs a green light',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function approvalGateStore(object $test, User $user, array $overrides = []): Post
{
    $test->actingAs($user)
        ->post(route('app.posts.store'), approvalGatePayload($test->channel, $overrides))
        ->assertSessionHasNoErrors();

    return Post::query()->findOrFail(session('created_post_ids')[0]);
}

function approvalGateSlot(Post $post): ?string
{
    return $post->refresh()->scheduled_at?->setTimezone('America/Sao_Paulo')->format('D H:i');
}

test('a requester queue request waits for approval without taking a slot', function () {
    $queued = approvalGateStore($this, $this->owner);
    $request = approvalGateStore($this, $this->requester, ['queue' => 'top']);

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($request->scheduled_at)->toBeNull()
        ->and($request->approval_queue_position)->toBe(QueuePosition::Top)
        ->and($request->approval_requested_at)->not->toBeNull()
        ->and($request->user_id)->toBe($this->requester->id)
        ->and(approvalGateSlot($queued))->toBe('Mon 09:00');
});

test('a requester custom time waits for approval with that time', function () {
    $at = CarbonImmutable::parse('2026-10-07 15:00', 'America/Sao_Paulo');
    $request = approvalGateStore($this, $this->requester, ['queue' => null, 'scheduled_at' => $at->toIso8601String()]);

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($request->scheduled_at->equalTo($at))->toBeTrue()
        ->and($request->approval_queue_position)->toBeNull();
});

test('a requester publish now request waits for approval without a time', function () {
    $request = approvalGateStore($this, $this->requester, ['status' => 'publishing', 'queue' => null]);

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->schedule_mode)->toBeNull()
        ->and($request->scheduled_at)->toBeNull();
    Queue::assertNotPushed(PublishPost::class);
});

test('a requester draft stays a draft', function () {
    $draft = approvalGateStore($this, $this->requester, ['status' => 'draft', 'queue' => null]);

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->approval_requested_at)->toBeNull();
});

test('a direct publisher is never gated', function () {
    $post = approvalGateStore($this, workspaceMember($this->workspace, 'member'));

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and(approvalGateSlot($post))->toBe('Mon 09:00');
});

test('a requester cannot reach the public api, so it can never bypass approval', function () {
    $token = passportToken($this->requester, $this->workspace);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson(route('api.posts.store'), [
            'status' => 'publishing',
            'content' => 'From the API',
            'platforms' => [[
                'social_account_id' => $this->channel->id,
                'content_type' => ContentType::LinkedInPost->value,
            ]],
        ])
        ->assertForbidden();

    expect(Post::query()->where('workspace_id', $this->workspace->id)->exists())->toBeFalse();
    Queue::assertNotPushed(PublishPost::class);
});

test('mcp publishing by a requester is stored as pending', function () {
    $draft = CreatePosts::execute($this->workspace, $this->requester, approvalGatePayload($this->channel, ['status' => 'draft', 'queue' => null]))->sole();

    TryPostServer::actingAs($this->requester)
        ->tool(PublishPostTool::class, ['post_id' => $draft->id, 'queue' => 'next'])
        ->assertOk();

    expect($draft->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($draft->fresh()->approval_queue_position)->toBe(QueuePosition::Next);
    Queue::assertNotPushed(PublishPost::class);
});

test('editing an approved queued post returns it to pending and keeps reserving its slot', function () {
    $first = approvalGateStore($this, $this->owner);
    $second = approvalGateStore($this, $this->owner);

    expect(approvalGateSlot($second))->toBe('Wed 09:00');

    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $first), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edited by a requester'])
        ->assertSessionHasNoErrors();

    $first->refresh();

    expect($first->status)->toBe(PostStatus::PendingApproval)
        ->and($first->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(approvalGateSlot($first))->toBe('Mon 09:00')
        ->and(approvalGateSlot($second))->toBe('Wed 09:00');
});

test('editing a pending post keeps the original request time', function () {
    $at = CarbonImmutable::parse('2026-10-07 15:00', 'America/Sao_Paulo');
    $request = approvalGateStore($this, $this->requester, ['queue' => null, 'scheduled_at' => $at->toIso8601String()]);
    $requestedAt = $request->approval_requested_at;
    $this->travel(5)->minutes();

    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $request), ['status' => 'scheduled', 'content' => 'Second try'])
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->content)->toContain('Second try')
        ->and($request->scheduled_at->equalTo($at))->toBeTrue()
        ->and($request->approval_requested_at->equalTo($requestedAt))->toBeTrue();
});

test('moving a pending post back to drafts clears the request', function () {
    $request = approvalGateStore($this, $this->requester);

    $this->actingAs($this->requester)
        ->put(route('app.posts.schedule.update', $request), ['action' => 'draft'])
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::Draft)
        ->and($request->approval_requested_at)->toBeNull()
        ->and($request->approval_queue_position)->toBeNull();
});

test('a direct publisher saving a pending post approves it', function () {
    $request = approvalGateStore($this, $this->requester);

    $this->actingAs($this->owner)
        ->put(route('app.posts.update', $request), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Fixed and approved'])
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::Scheduled)
        ->and($request->approved_by)->toBe($this->owner->id)
        ->and($request->approved_at)->not->toBeNull()
        ->and($request->approval_queue_position)->toBeNull()
        ->and(approvalGateSlot($request))->toBe('Mon 09:00');
});

test('the scheduler never publishes a pending post', function () {
    $post = Post::factory()->pendingApproval()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->requester->id,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan('posts:process-scheduled')->assertSuccessful();

    expect($post->fresh()->status)->toBe(PostStatus::PendingApproval);
    Queue::assertNotPushed(PublishPost::class);
});

test('a pending post opens in the composer', function () {
    $request = approvalGateStore($this, $this->requester);

    $this->actingAs($this->requester)
        ->get(route('app.posts.edit', $request))
        ->assertRedirect(route('app.posts.index', ['edit' => $request->id]));
});

test('a requester publish now request with a time waits for approval without the time', function () {
    TryPostServer::actingAs($this->requester)
        ->tool(CreatePostsTool::class, approvalGatePayload($this->channel, [
            'status' => 'publishing',
            'queue' => null,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]))
        ->assertOk();

    $request = Post::query()->sole();

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->scheduled_at)->toBeNull()
        ->and($request->schedule_mode)->toBeNull();
    Queue::assertNotPushed(PublishPost::class);
});

test('an mcp edit of a pending post without a status keeps the request', function () {
    $request = approvalGateStore($this, $this->requester, ['queue' => 'top']);
    $requestedAt = $request->approval_requested_at;
    $this->travel(5)->minutes();

    TryPostServer::actingAs($this->requester)
        ->tool(UpdatePostTool::class, ['post_id' => $request->id, 'content' => 'Edited while pending'])
        ->assertOk();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->content)->toContain('Edited while pending')
        ->and($request->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($request->scheduled_at)->toBeNull()
        ->and($request->approval_queue_position)->toBe(QueuePosition::Top)
        ->and($request->approval_requested_at->equalTo($requestedAt))->toBeTrue();
});

test('a request records who asked and moving it to drafts clears it', function () {
    $request = approvalGateStore($this, $this->requester);

    expect($request->approval_requested_by)->toBe($this->requester->id);

    $this->actingAs($this->requester)
        ->put(route('app.posts.schedule.update', $request), ['action' => 'draft'])
        ->assertSessionHasNoErrors();

    expect($request->refresh()->approval_requested_by)->toBeNull();
});

test('a requester editing someone else\'s approved post becomes the one who asked', function () {
    $scheduled = approvalGateStore($this, $this->owner);

    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $scheduled), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Typo fixed'])
        ->assertSessionHasNoErrors();

    $scheduled->refresh();

    expect($scheduled->status)->toBe(PostStatus::PendingApproval)
        ->and($scheduled->user_id)->toBe($this->owner->id)
        ->and($scheduled->approval_requested_by)->toBe($this->requester->id);
});

test('an approved request keeps who asked', function () {
    $request = approvalGateStore($this, $this->requester);

    ApprovePost::execute($request, $this->owner);

    expect($request->refresh()->status)->toBe(PostStatus::Scheduled)
        ->and($request->approval_requested_by)->toBe($this->requester->id);
});

test('returning an approved post to approval queues a post.unscheduled webhook', function () {
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostUnscheduled->value],
    ]);
    $scheduled = approvalGateStore($this, $this->owner);

    $this->actingAs($this->requester)
        ->put(route('app.posts.update', $scheduled), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edited'])
        ->assertSessionHasNoErrors();

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostUnscheduled->value
        && data_get($job->payload, 'id') === $scheduled->id
        && data_get($job->payload, 'status') === PostStatus::PendingApproval->value);
});

test('a requester publishing or queueing a draft from its card waits for approval', function (string $action, ?QueuePosition $position) {
    $draft = approvalGateStore($this, $this->requester, ['status' => 'draft', 'queue' => null]);

    $this->actingAs($this->requester)
        ->put(route('app.posts.schedule.update', $draft), ['action' => $action])
        ->assertSessionHasNoErrors();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::PendingApproval)
        ->and($draft->scheduled_at)->toBeNull()
        ->and($draft->approval_queue_position)->toBe($position)
        ->and($draft->approval_requested_by)->toBe($this->requester->id);
    Queue::assertNotPushed(PublishPost::class);
})->with([
    'publish now' => ['publish_now', null],
    'queue next' => ['queue_next', QueuePosition::Next],
]);

test('a requester creating posts through mcp waits for approval', function () {
    TryPostServer::actingAs($this->requester)
        ->tool(CreatePostsTool::class, approvalGatePayload($this->channel))
        ->assertOk();

    $request = Post::query()->sole();

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->scheduled_at)->toBeNull()
        ->and($request->approval_queue_position)->toBe(QueuePosition::Next)
        ->and($request->approval_requested_by)->toBe($this->requester->id);
});

test('a requester editing an approved post through mcp returns it to approval', function () {
    $scheduled = approvalGateStore($this, $this->owner, ['queue' => null, 'scheduled_at' => now()->addDays(2)->toIso8601String()]);

    TryPostServer::actingAs($this->requester)
        ->tool(UpdatePostTool::class, ['post_id' => $scheduled->id, 'status' => 'scheduled', 'content' => 'Edited through mcp'])
        ->assertOk();

    $scheduled->refresh();

    expect($scheduled->status)->toBe(PostStatus::PendingApproval)
        ->and($scheduled->content)->toContain('Edited through mcp')
        ->and($scheduled->approval_requested_by)->toBe($this->requester->id);
});

/**
 * A legacy post with two enabled targets, scheduled by the owner.
 */
function approvalGateLegacyPost(object $test, PostStatus $status = PostStatus::Scheduled): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $test->workspace->id,
        'user_id' => $test->owner->id,
        'status' => $status,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(2),
        'content' => 'Legacy post',
        'approval_requested_at' => $status === PostStatus::PendingApproval ? now() : null,
    ]);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $test->workspace->id]);

    foreach ([$test->channel, $x] as $channel) {
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $channel->id,
            'platform' => $channel->platform,
            'content_type' => $channel->platform === Platform::X ? ContentType::XPost : ContentType::LinkedInPost,
            'enabled' => true,
        ]);
    }

    return $post;
}

test('a requester editing a legacy multi-target post returns it to approval', function () {
    $legacy = approvalGateLegacyPost($this);
    $requestedAt = $legacy->scheduled_at;

    UpdatePost::execute($this->workspace, $legacy, ['status' => 'scheduled', 'content' => 'Legacy edit'], $this->requester);

    $legacy->refresh();

    expect($legacy->status)->toBe(PostStatus::PendingApproval)
        ->and($legacy->scheduled_at->equalTo($requestedAt))->toBeTrue()
        ->and($legacy->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($legacy->approval_requested_by)->toBe($this->requester->id);
    Queue::assertNotPushed(PublishPost::class);
});

test('approving a legacy multi-target request to publish now drops the requested time', function () {
    $legacy = approvalGateLegacyPost($this, PostStatus::PendingApproval);

    UpdatePost::execute($this->workspace, $legacy, ['status' => 'publishing'], $this->owner);

    $legacy->refresh();

    expect($legacy->status)->toBe(PostStatus::Publishing)
        ->and($legacy->scheduled_at->equalTo(now()))->toBeTrue()
        ->and($legacy->approved_by)->toBe($this->owner->id);
    Queue::assertPushed(PublishPost::class);
});
