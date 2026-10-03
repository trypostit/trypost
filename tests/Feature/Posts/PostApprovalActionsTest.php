<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Actions\Post\Queue\BuildQueueTimeline;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType;
use App\Jobs\DispatchWebhook;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
 */
function approvalActionsPost(object $test, User $author, array $overrides = []): Post
{
    return CreatePosts::execute($test->workspace, $author, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Please approve',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $test->channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->sole();
}

function approvalActionsSlot(Post $post): ?string
{
    return $post->refresh()->scheduled_at?->setTimezone('America/Sao_Paulo')->format('D H:i');
}

test('approving a queue request adds it to the queue where the author asked', function () {
    $queued = approvalActionsPost($this, $this->owner);
    $request = approvalActionsPost($this, $this->requester, ['queue' => 'top']);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::Scheduled)
        ->and($request->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(approvalActionsSlot($request))->toBe('Mon 09:00')
        ->and(approvalActionsSlot($queued))->toBe('Wed 09:00')
        ->and($request->approved_by)->toBe($this->owner->id)
        ->and($request->approved_at)->not->toBeNull()
        ->and($request->approval_queue_position)->toBeNull();
});

test('approving a custom request schedules it at the requested time', function () {
    $at = CarbonImmutable::parse('2026-10-08 18:30', 'America/Sao_Paulo');
    $request = approvalActionsPost($this, $this->requester, ['queue' => null, 'scheduled_at' => $at->toIso8601String()]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::Scheduled)
        ->and($request->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($request->scheduled_at->equalTo($at))->toBeTrue();
});

test('approving after the requested time asks for a new time', function () {
    $request = approvalActionsPost($this, $this->requester, ['queue' => null, 'scheduled_at' => now()->addHour()->toIso8601String()]);
    $this->travel(2)->hours();

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasErrors('scheduled_at');

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($request->fresh()->approved_by)->toBeNull();

    $newTime = now()->addDay();

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request), ['scheduled_at' => $newTime->toIso8601String()])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($request->fresh()->scheduled_at->equalTo($newTime))->toBeTrue();
});

test('approving after the requested time can publish now', function () {
    $request = approvalActionsPost($this, $this->requester, ['queue' => null, 'scheduled_at' => now()->addHour()->toIso8601String()]);
    $this->travel(2)->hours();

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request), ['publish_now' => true])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($request->fresh()->approved_by)->toBe($this->owner->id)
        ->and($request->fresh()->scheduled_at->equalTo(now()))->toBeTrue();
    Queue::assertPushed(PublishPost::class);
});

test('approving a request without a time asks for one or publishes now', function () {
    $request = approvalActionsPost($this, $this->requester, ['status' => 'publishing', 'queue' => null]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasErrors('scheduled_at');

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request), ['publish_now' => true])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($request->fresh()->approved_by)->toBe($this->owner->id);
    Queue::assertPushed(PublishPost::class);
});

test('approving a queue request on a channel without posting times keeps it pending', function () {
    $request = approvalActionsPost($this, $this->requester);
    $this->channel->update(['posting_schedule' => null]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasErrors('queue');

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($request->fresh()->approved_by)->toBeNull();
});

test('a second approval is rejected', function () {
    $request = approvalActionsPost($this, $this->requester);

    $this->actingAs($this->owner)->put(route('app.posts.approve', $request))->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->put(route('app.posts.approve', $request))->assertSessionHasErrors('post');

    expect(Post::query()->scheduled()->count())->toBe(1);
});

test('rejecting moves the post back to drafts and keeps its time', function () {
    $at = CarbonImmutable::parse('2026-10-08 18:30', 'America/Sao_Paulo');
    $request = approvalActionsPost($this, $this->requester, ['queue' => null, 'scheduled_at' => $at->toIso8601String()]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.reject', $request))
        ->assertSessionHasNoErrors();

    $request->refresh();

    expect($request->status)->toBe(PostStatus::Draft)
        ->and($request->schedule_mode)->toBeNull()
        ->and($request->approval_requested_at)->toBeNull()
        ->and($request->scheduled_at->equalTo($at))->toBeTrue();
});

test('a direct publisher who is not an admin can approve', function () {
    $request = approvalActionsPost($this, $this->requester);

    $this->actingAs(workspaceMember($this->workspace, 'member'))
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('members who need approval cannot approve or reject', function () {
    $request = approvalActionsPost($this, $this->requester);

    $this->actingAs(workspaceMember($this->workspace, 'approval'))
        ->put(route('app.posts.approve', $request))
        ->assertForbidden();

    $this->actingAs($this->requester)
        ->put(route('app.posts.reject', $request))
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('a post of another workspace is not found', function () {
    $foreign = Post::factory()->pendingApproval()->create();

    $this->actingAs($this->owner)->put(route('app.posts.approve', $foreign))->assertNotFound();
    $this->actingAs($this->owner)->put(route('app.posts.reject', $foreign))->assertNotFound();
});

test('approving notifies post scheduled webhooks', function () {
    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostScheduled->value],
    ]);
    $request = approvalActionsPost($this, $this->requester);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasNoErrors();

    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job): bool => $job->eventType === EventType::PostScheduled->value
        && data_get($job->payload, 'id') === $request->id);
});

test('approving while another approval holds the post reports the queue as busy', function () {
    $request = approvalActionsPost($this, $this->requester);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", 30)->andReturn($lock);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasErrors(['queue' => __('posts.errors.queue_busy')]);

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('approving a legacy request that misses required meta keeps it pending', function () {
    $request = Post::factory()->pendingApproval()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->requester->id,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $request->id,
        'social_account_id' => $this->channel->id,
    ]);
    PostPlatform::factory()->discord()->create([
        'post_id' => $request->id,
        'social_account_id' => SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id])->id,
    ]);

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $request))
        ->assertSessionHasErrors();

    expect(session('errors')->all())->toContain(__('posts.form.discord.channel_required'))
        ->and($request->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($request->fresh()->approved_by)->toBeNull();
});

test('rejecting while another approval holds the post reports the queue as busy', function () {
    $request = approvalActionsPost($this, $this->requester);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", 30)->andReturn($lock);

    $this->actingAs($this->owner)
        ->put(route('app.posts.reject', $request))
        ->assertSessionHasErrors(['queue' => __('posts.errors.queue_busy')]);

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('approving from the composer waits for the same approval lock', function () {
    $request = approvalActionsPost($this, $this->requester);
    $lock = Mockery::mock(Lock::class);
    $lock->shouldReceive('block')->andThrow(new LockTimeoutException);
    Cache::partialMock()->shouldReceive('lock')->with("post-approval:{$request->id}", 30)->andReturn($lock);

    $this->actingAs($this->owner)
        ->put(route('app.posts.update', $request), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Approved in the composer'])
        ->assertSessionHasErrors(['queue' => __('posts.errors.queue_busy')]);

    expect($request->fresh()->status)->toBe(PostStatus::PendingApproval);
});

function approvalActionsEditQueued(object $test, Post $post): void
{
    $test->actingAs($test->requester)
        ->put(route('app.posts.update', $post), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edited by the member'])
        ->assertSessionHasNoErrors();
}

test('a queued post edited by a member who needs approval keeps reserving its slot until approved', function () {
    $edited = approvalActionsPost($this, $this->owner);
    approvalActionsEditQueued($this, $edited);
    $edited->refresh();

    expect($edited->status)->toBe(PostStatus::PendingApproval)
        ->and($edited->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(approvalActionsSlot($edited))->toBe('Mon 09:00');

    $other = approvalActionsPost($this, $this->owner);
    $top = approvalActionsPost($this, $this->owner, ['queue' => 'top']);
    $freeSlots = collect(BuildQueueTimeline::handle($this->workspace, collect([$this->channel->fresh()]), 'America/Sao_Paulo', now()->addDays(7)))
        ->flatMap(fn (array $day): array => $day['items'])
        ->where('type', 'slot')
        ->map(fn (array $item): string => CarbonImmutable::parse($item['at'])->setTimezone('America/Sao_Paulo')->format('D H:i'));

    expect(approvalActionsSlot($other))->toBe('Fri 09:00')
        ->and(approvalActionsSlot($top))->toBe('Wed 09:00')
        ->and($freeSlots->all())->not->toContain('Mon 09:00');

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $edited))
        ->assertSessionHasNoErrors();

    expect($edited->refresh()->status)->toBe(PostStatus::Scheduled)
        ->and($edited->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(approvalActionsSlot($edited))->toBe('Mon 09:00')
        ->and(approvalActionsSlot($other))->toBe('Fri 09:00')
        ->and(approvalActionsSlot($top))->toBe('Wed 09:00');
});

test('rejecting a pending edit of a queued post frees its slot', function () {
    $edited = approvalActionsPost($this, $this->owner);
    approvalActionsEditQueued($this, $edited);

    $this->actingAs($this->owner)
        ->put(route('app.posts.reject', $edited))
        ->assertSessionHasNoErrors();

    expect(approvalActionsSlot(approvalActionsPost($this, $this->owner)))->toBe('Mon 09:00');
});

test('approving a pending edit whose slot vanished places it in the first free slot', function () {
    $edited = approvalActionsPost($this, $this->owner);
    approvalActionsEditQueued($this, $edited);

    $this->actingAs($this->owner)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 2,
            'posting_schedule' => PostingSchedule::empty()->withTime(3, '09:00')->withTime(5, '09:00')->toArray(),
        ])
        ->assertOk();

    $this->actingAs($this->owner)
        ->put(route('app.posts.approve', $edited))
        ->assertSessionHasNoErrors();

    expect(approvalActionsSlot($edited))->toBe('Wed 09:00')
        ->and($edited->schedule_mode)->toBe(ScheduleMode::Queue);
});
