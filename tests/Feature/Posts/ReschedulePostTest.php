<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')->withTime(5, '09:00'),
    ]);
});

function reschedulePost(SocialAccount $channel, User $user, array $attributes = []): Post
{
    return Post::factory()->forAccount($channel)->create(array_merge([
        'user_id' => $user->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
        'schedule_mode' => null,
        'scheduled_at' => null,
    ], $attributes))->refresh();
}

function rescheduleCustom(SocialAccount $channel, User $user, string $at = '2026-10-06 10:00'): Post
{
    return reschedulePost($channel, $user, [
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);
}

function rescheduleQueued(SocialAccount $channel, User $user): Post
{
    $post = reschedulePost($channel, $user, ['status' => PostStatus::Scheduled, 'schedule_mode' => ScheduleMode::Queue]);

    ReflowChannelQueue::handle($channel, $post, QueuePosition::Next);

    return $post->refresh();
}

test('reschedule moves a custom post to the instant it receives', function () {
    $post = rescheduleCustom($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-06T14:00:00Z'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-06 14:00', 'UTC')->toIso8601String());
});

test('reschedule stores the instant it receives, not the channel clock', function () {
    $this->channel->update(['timezone' => 'America/Sao_Paulo']);
    $post = rescheduleCustom($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-06T01:00:00Z'])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-06 01:00', 'UTC')->toIso8601String());
});

test('reschedule keeps a queued post in the queue when asked to and the target is a free slot of its channel', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'keep_in_queue' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('reschedule makes a queued post custom on a free slot when it was not asked to stay queued', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String()])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('reschedule keeps a queued post in place when the slot it asks for is already taken', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slotAt = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');
    $before = $post->scheduled_at->toIso8601String();
    $occupant = rescheduleCustom($this->channel, $this->user, $slotAt->toDateTimeString());

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slotAt->toIso8601String(), 'keep_in_queue' => true])
        ->assertRedirect()
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe($before)
        ->and($occupant->fresh()->scheduled_at->toIso8601String())->toBe($slotAt->toIso8601String());
});

test('reschedule keeps a custom post custom even when asked to stay queued', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'keep_in_queue' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('reschedule makes a queued post custom when the target is not a slot', function () {
    $first = rescheduleQueued($this->channel, $this->user);
    $second = rescheduleQueued($this->channel, $this->user);
    $oldSlot = $first->scheduled_at;
    $secondSlot = $second->scheduled_at->toIso8601String();
    $target = CarbonImmutable::parse('2026-10-06 15:30', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $first), ['action' => 'reschedule', 'scheduled_at' => $target->toIso8601String()])
        ->assertSessionHasNoErrors();

    $first->refresh();

    expect($first->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($first->scheduled_at->toIso8601String())->toBe($target->toIso8601String())
        ->and(ReflowChannelQueue::isFreeSlot($this->channel->refresh(), $oldSlot))->toBeTrue()
        ->and($second->fresh()->scheduled_at->toIso8601String())->toBe($secondSlot);
});

test('reschedule keeps a custom post custom on a free slot of its channel', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String()])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('reschedule keeps a draft a draft and moves its date', function () {
    $draft = reschedulePost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 10:00', 'UTC')]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertSessionHasNoErrors();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-08 11:00', 'UTC')->toIso8601String());
});

test('reschedule with a null time removes a draft date', function () {
    $draft = reschedulePost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 10:00', 'UTC')]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'reschedule', 'scheduled_at' => null])
        ->assertSessionHasNoErrors();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->scheduled_at)->toBeNull();
});

test('reschedule with a null time is refused for a scheduled post', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => null])
        ->assertRedirect()
        ->assertSessionHasErrors('scheduled_at');

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toIso8601String())->toBe($at);
});

test('reschedule refuses a past instant', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => now()->subMinute()->toIso8601String()])
        ->assertRedirect()
        ->assertSessionHasErrors('scheduled_at');

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($at);
});

test('reschedule refuses a channel-less draft', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('posts.errors.choose_channel')]);
});

test('reschedule by a member who needs approval stores a pending request', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $post = rescheduleCustom($this->channel, $member);

    $this->actingAs($member)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertSessionHasNoErrors();

    expect($post->fresh()->status)->toBe(PostStatus::PendingApproval);
});

test('reschedule onto a slot taken meanwhile fails with queue_order_stale', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slotAt = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');
    $before = $post->scheduled_at->toIso8601String();
    $taken = false;

    Post::retrieved(function (Post $retrieved) use (&$taken, $post, $slotAt): void {
        if ($taken || ! $retrieved->is($post)) {
            return;
        }

        $probe = Cache::lock("queue:{$this->channel->id}");

        if ($probe->get()) {
            $probe->release();

            return;
        }

        $taken = true;
        rescheduleCustom($this->channel, $this->user, $slotAt->toDateTimeString());
    });

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slotAt->toIso8601String(), 'keep_in_queue' => true])
        ->assertRedirect()
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($before);
});

test('reschedule of a queued post by a member who needs approval stores a pending queue request', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $post = rescheduleQueued($this->channel, $member);
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($member)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'keep_in_queue' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->approval_requested_by)->toBe($member->id);
});

test('reschedule of a published post does nothing', function () {
    $post = reschedulePost($this->channel, $this->user, [
        'status' => PostStatus::Published,
        'scheduled_at' => CarbonImmutable::parse('2026-10-04 10:00', 'UTC'),
    ]);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('posts.flash.cannot_edit_finalized'));

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Published)
        ->and($post->scheduled_at->toIso8601String())->toBe($at);
});

test('reschedule of a post the viewer cannot see is not found', function () {
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'platform' => Platform::LinkedIn]);
    $post = rescheduleCustom($otherChannel, $other);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertNotFound();
});
