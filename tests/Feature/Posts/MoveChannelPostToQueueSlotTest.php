<?php

declare(strict_types=1);

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $schedule = PostingSchedule::empty();
    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, '09:00')->withTime($day, '12:00')->withTime($day, '18:00');
    }

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);

    $this->a = moveToSlotQueuedPost($this->channel, $this->user);
    $this->b = moveToSlotQueuedPost($this->channel, $this->user);
});

function moveToSlotQueuedPost(SocialAccount $channel, User $user): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'content' => 'Queued post',
        'scheduled_at' => null,
    ]);

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform]);

    Cache::lock("queue:{$channel->id}")->forceRelease();
    ReflowChannelQueue::handle($channel, $post, QueuePosition::Next);

    return $post->refresh();
}

/**
 * @param  array<string, mixed>  $attributes
 */
function moveToSlotCustomPost(SocialAccount $channel, User $user, array $attributes = []): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'content' => 'Custom post',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => CarbonImmutable::parse('2026-10-20 15:30:00', 'UTC'),
        ...$attributes,
    ]);

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform]);

    return $post;
}

test('a custom post dropped on a free slot becomes a queue post at that slot', function () {
    $custom = moveToSlotCustomPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $custom->refresh();

    expect($custom->scheduled_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00')
        ->and($custom->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($custom->status)->toBe(PostStatus::Scheduled)
        ->and($this->a->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-05T09:00:00+00:00')
        ->and($this->b->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');
});

test('a queued post dropped on a later free slot moves there and leaves the others in place', function () {
    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $this->a->id, 'slot_at' => '2026-10-05T18:00:00Z'])
        ->assertSessionHasNoErrors();

    expect($this->a->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-05T18:00:00+00:00')
        ->and($this->a->fresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($this->b->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');
});

test('a target that is not a free future slot of the channel is rejected', function (string $slotAt) {
    $custom = moveToSlotCustomPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => $slotAt])
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    expect($custom->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-20T15:30:00+00:00')
        ->and($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
})->with([
    'not a posting time' => '2026-10-06T10:30:00+00:00',
    'in the past' => '2026-10-04T18:00:00+00:00',
    'occupied by a queued post' => '2026-10-05T12:00:00+00:00',
    'beyond the queue horizon' => '2027-03-01T09:00:00+00:00',
]);

test('a post that does not belong to the channel or is not scheduled is rejected', function (string $kind) {
    $post = match ($kind) {
        'other channel' => moveToSlotCustomPost(
            SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]),
            $this->user,
        ),
        'draft' => moveToSlotCustomPost($this->channel, $this->user, ['status' => PostStatus::Draft, 'schedule_mode' => null]),
        'pending approval' => moveToSlotCustomPost($this->channel, $this->user, ['status' => PostStatus::PendingApproval]),
        'published' => moveToSlotCustomPost($this->channel, $this->user, ['status' => PostStatus::Published, 'scheduled_at' => now()->subHour()]),
        'failed' => moveToSlotCustomPost($this->channel, $this->user, ['status' => PostStatus::Failed]),
        'due within a minute' => moveToSlotCustomPost($this->channel, $this->user, ['scheduled_at' => now()->addSeconds(30)]),
        'other workspace' => moveToSlotCustomPost(SocialAccount::factory()->create(), $this->user),
    };
    $before = $post->fresh()->only(['status', 'scheduled_at', 'schedule_mode']);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $post->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    expect($post->fresh()->only(['status', 'scheduled_at', 'schedule_mode']))->toEqual($before);
})->with(['other channel', 'draft', 'other workspace', 'pending approval', 'published', 'failed', 'due within a minute']);

test('a post created for several channels at once cannot be moved to a slot', function () {
    $legacy = moveToSlotCustomPost($this->channel, $this->user);
    PostPlatform::factory()->create(['post_id' => $legacy->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id]);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $legacy->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertSessionHasErrors('queue');

    expect($legacy->fresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($legacy->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-20T15:30:00+00:00');
});

test('a member who needs approval cannot move a post to a slot', function () {
    $requester = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));
    $custom = moveToSlotCustomPost($this->channel, $requester);

    $this->actingAs($requester)
        ->putJson(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertForbidden();

    expect($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('a channel of another workspace is not found', function () {
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.slot', $channel), ['post_id' => $this->a->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertNotFound();
});

test('a busy queue lock returns a conflict and moves nothing', function () {
    $custom = moveToSlotCustomPost($this->channel, $this->user);
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    expect($lock->get())->toBeTrue();
    $this->travelBack();

    try {
        $this->actingAs($this->user)
            ->putJson(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
            ->assertConflict();
    } finally {
        $lock->release();
    }

    expect($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('the request requires a post id and a slot instant', function () {
    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.slot', $this->channel), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['post_id', 'slot_at']);
});

test('the move still goes through the approval gate of the acting user', function () {
    $requester = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));
    $custom = moveToSlotCustomPost($this->channel, $requester);

    MoveChannelPostToQueueSlot::handle($this->channel, $custom->id, CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'), $requester);

    expect($custom->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($custom->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00');
});

test('a dropped post keeps its slot when another post is added to the queue', function () {
    $custom = moveToSlotCustomPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-10-06T12:00:00+00:00'])
        ->assertSessionHasNoErrors();

    $added = moveToSlotQueuedPost($this->channel, $this->user);

    expect($custom->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00')
        ->and($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($added->scheduled_at->toIso8601String())->toBe('2026-10-05T18:00:00+00:00');
});

test('a slot taken by a custom post is not free', function () {
    moveToSlotCustomPost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC')]);
    $custom = moveToSlotCustomPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-10-06T09:00:00+00:00'])
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    expect($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('slots are validated in the channel time zone across a daylight saving change', function () {
    $this->channel->update(['timezone' => 'America/New_York']);
    $custom = moveToSlotCustomPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-11-02T13:00:00+00:00'])
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.slot', $this->channel), ['post_id' => $custom->id, 'slot_at' => '2026-11-02T14:00:00+00:00'])
        ->assertSessionHasNoErrors();

    expect($custom->fresh()->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-02 09:00')
        ->and($custom->fresh()->schedule_mode)->toBe(ScheduleMode::Queue);
});

test('a queued post moved by a member who needs approval goes to approval without a queue lock clash', function () {
    Exceptions::fake();
    $requester = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));

    MoveChannelPostToQueueSlot::handle($this->channel, $this->a->id, CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'), $requester);

    Exceptions::assertNothingReported();
    expect($this->a->fresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($this->a->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-06T12:00:00+00:00')
        ->and($this->b->fresh()->scheduled_at->toIso8601String())->toBe('2026-10-05T12:00:00+00:00');
});
