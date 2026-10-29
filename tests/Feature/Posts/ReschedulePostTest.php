<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
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

test('a queued post dropped on a free slot of its own channel becomes custom at that slot', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String()])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('a post dropped on a slot another post already holds stacks on that instant', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slotAt = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');
    $occupant = rescheduleCustom($this->channel, $this->user, $slotAt->toDateTimeString());

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slotAt->toIso8601String()])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slotAt->toIso8601String())
        ->and($occupant->fresh()->scheduled_at->toIso8601String())->toBe($slotAt->toIso8601String());
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

test('a custom post sent to a free slot of its channel goes into the queue at that slot', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('a queued post sent to another free slot stays in the queue there and frees its old slot', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $oldSlot = $post->scheduled_at;
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String())
        ->and(ReflowChannelQueue::isFreeSlot($this->channel->refresh(), $oldSlot))->toBeTrue();
});

test('a post sent to a slot another post holds is refused and keeps its time', function () {
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');
    rescheduleCustom($this->channel, $this->user, $slot->toDateTimeString());
    $post = rescheduleCustom($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasErrors(['slot_at' => __('posts.errors.queue_order_stale')]);

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-06 10:00', 'UTC')->toIso8601String());
});

test('a post sent to the queue at an instant that is not a slot is refused instead of becoming custom', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $queuedAt = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-06T15:30:00Z', 'queue_slot' => true])
        ->assertSessionHasErrors('slot_at');

    $post->refresh();

    expect($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe($queuedAt);
});

test('a post sent to a slot past the queue window becomes custom at that slot', function () {
    $post = rescheduleQueued($this->channel, $this->user);
    $slot = CarbonImmutable::parse('2027-02-03 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('a post sent to a slot after it started publishing shows the finalized banner and keeps its time', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();
    $retrievals = 0;

    Post::retrieved(function (Post $retrieved) use ($post, &$retrievals): void {
        if ($retrieved->id === $post->id && ++$retrievals === 3) {
            Post::query()->whereKey($post->id)->toBase()->update(['status' => PostStatus::Publishing->value]);
        }
    });

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-07T09:00:00Z', 'queue_slot' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('flash.banner', __('posts.flash.cannot_edit_finalized'));

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Publishing)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->toIso8601String())->toBe($at);
});

test('a draft sent to a slot stays a draft with that date', function () {
    $draft = reschedulePost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 10:00', 'UTC')]);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasNoErrors();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->scheduled_at->toIso8601String())->toBe($slot->toIso8601String());
});

test('a post sent to a slot by a member who needs approval stores a pending request', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $post = rescheduleCustom($this->channel, $member);
    $slot = CarbonImmutable::parse('2026-10-07 09:00', 'UTC');

    $this->actingAs($member)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String(), 'queue_slot' => true])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->approval_requested_by)->toBe($member->id);
});

test('another action ignores queue_slot', function () {
    $draft = reschedulePost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'queue_next', 'queue_slot' => 'not-a-boolean'])
        ->assertSessionHasNoErrors();

    expect($draft->fresh()->schedule_mode)->toBe(ScheduleMode::Queue);
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

test('reschedule with a null time is refused for a scheduled post and asks for a time', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => null])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.required')]);

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toIso8601String())->toBe($at);
});

test('reschedule refuses a past instant and asks for a later time', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => now()->subMinute()->toIso8601String()])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.past')]);

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

test('reschedule of a queued post by a member who needs approval stores a pending custom time request', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $post = rescheduleQueued($this->channel, $member);
    $slot = CarbonImmutable::parse('2026-10-09 09:00', 'UTC');

    $this->actingAs($member)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => $slot->toIso8601String()])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
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

test('reschedule keeps a recurring post\'s rule and remaining count', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $post->forceFill([
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_interval' => 1,
        'recurrence_remaining' => 3,
    ])->save();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-09T10:00:00Z'])
        ->assertSessionHasNoErrors();

    $post->refresh();

    expect($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_interval)->toBe(1)
        ->and($post->recurrence_remaining)->toBe(3)
        ->and($post->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-09 10:00', 'UTC')->toIso8601String());
});

test('the next occurrence of a rescheduled recurring post follows the new time', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $post->forceFill([
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_interval' => 1,
        'recurrence_remaining' => 3,
    ])->save();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-09T10:00:00Z'])
        ->assertSessionHasNoErrors();

    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:05', 'UTC'));
    $post->refresh()->forceFill(['status' => PostStatus::Publishing, 'publish_status' => PublishStatus::Published])->save();
    app(FinalizePostPublication::class)->handle($post);

    $next = Post::query()->whereKeyNot($post->id)->where('workspace_id', $this->workspace->id)->sole();

    expect($next->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-16 10:00', 'UTC')->toIso8601String())
        ->and($next->recurrence_remaining)->toBe(2)
        ->and($next->recurrence_frequency)->toBe(RecurrenceFrequency::Week);
});

test('reschedule without a time field is refused and keeps a draft\'s date', function () {
    $draft = reschedulePost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2026-10-06 10:00', 'UTC')]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'reschedule'])
        ->assertRedirect()
        ->assertSessionHasErrors('scheduled_at');

    expect($draft->fresh()->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-06 10:00', 'UTC')->toIso8601String());
});

test('another action ignores a stray past time', function () {
    $draft = reschedulePost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'queue_next', 'scheduled_at' => now()->subDay()->toIso8601String()])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Scheduled)
        ->and($draft->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($draft->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-05 09:00', 'UTC')->toIso8601String());
});

test('reschedule from the publish list stays on the page it came from', function () {
    $post = rescheduleCustom($this->channel, $this->user);

    $this->actingAs($this->user)
        ->from(route('app.posts.index'))
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-06T14:00:00Z'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));
});

test('reschedule of a pending post is refused and keeps the request as it is', function (string $who) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $post = rescheduleCustom($this->channel, $member);

    $this->actingAs($member)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertSessionHasNoErrors();

    $pending = $post->fresh();
    $actor = $who === 'requester' ? $member : $this->user;

    $this->actingAs($actor)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-09T15:00:00Z'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.pending')]);

    $post->refresh();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->scheduled_at?->toIso8601String())->toBe($pending->scheduled_at?->toIso8601String())
        ->and($post->approved_by)->toBeNull()
        ->and($post->approval_requested_by)->toBe($member->id);
})->with(['requester', 'approver']);

test('reschedule of a failed or publishing post does nothing', function (PostStatus $status) {
    $post = reschedulePost($this->channel, $this->user, [
        'status' => $status,
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 07:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertRedirect()
        ->assertSessionHas('flash.banner', __('posts.flash.cannot_edit_finalized'));

    $post->refresh();

    expect($post->status)->toBe($status)
        ->and($post->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-05 07:00', 'UTC')->toIso8601String());
})->with([PostStatus::Failed, PostStatus::Publishing]);

test('reschedule of a scheduled post due within a minute or overdue is refused', function (string $due) {
    $post = rescheduleCustom($this->channel, $this->user, now()->modify($due)->toDateTimeString());
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.publishing_soon')]);

    $post->refresh();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->scheduled_at->toIso8601String())->toBe($at);
})->with(['in 20 seconds' => '+20 seconds', '30 seconds ago' => '-30 seconds']);

test('reschedule of a scheduled post due in two minutes moves it', function () {
    $post = rescheduleCustom($this->channel, $this->user, now()->addMinutes(2)->toDateTimeString());

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-08 11:00', 'UTC')->toIso8601String());
});

test('reschedule is refused when the post went to approval after the request was validated', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();
    $flipped = false;

    Post::retrieved(function (Post $retrieved) use ($post, &$flipped): void {
        if ($flipped || $retrieved->id !== $post->id) {
            return;
        }

        $flipped = true;
        Post::query()->whereKey($post->id)->toBase()->update(['status' => PostStatus::PendingApproval->value]);
    });

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2026-10-08T11:00:00Z'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.pending')]);

    $post->refresh();

    expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->scheduled_at->toIso8601String())->toBe($at)
        ->and($post->approved_by)->toBeNull();
});

test('reschedule refuses a time past the 2038 ceiling before it reaches the post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => '2038-01-19T00:00:00Z'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('calendar.reschedule.errors.too_far')]);
});

test('reschedule refuses a malformed time', function () {
    $post = rescheduleCustom($this->channel, $this->user);
    $at = $post->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'reschedule', 'scheduled_at' => 'next tuesday-ish'])
        ->assertRedirect()
        ->assertSessionHasErrors(['scheduled_at' => __('validation.date', ['attribute' => 'scheduled at'])]);

    expect($post->fresh()->scheduled_at->toIso8601String())->toBe($at);
});
