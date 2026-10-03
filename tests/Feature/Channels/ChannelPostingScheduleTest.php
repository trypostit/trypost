<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\QueueBusyException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
});

function schedulePayload(array $overrides = []): array
{
    return [
        'timezone' => 'Europe/Warsaw',
        'posting_goal' => 4,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '18:30')->toArray(),
        ...$overrides,
    ];
}

test('the settings page renders the channel, schedule, time zones and other channels', function () {
    $other = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'posting_schedule' => PostingSchedule::empty()->withTime(0, '10:00'),
    ]);
    SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.channels.settings', $this->channel))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('channels/Settings', false)
            ->where('channel.id', $this->channel->id)
            ->has('schedule.timezone')
            ->has('timezones')
            ->has('otherChannels', 1)
            ->where('otherChannels.0.id', $other->id)
        );
});

test('members cannot open channel settings', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->get(route('app.channels.settings', $this->channel))->assertForbidden();
    $this->actingAs($member)->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload())->assertForbidden();
});

test('updating saves time zone, goal and schedule', function () {
    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload())
        ->assertOk()
        ->assertJsonPath('timezone', 'Europe/Warsaw')
        ->assertJsonPath('posting_goal', 4);

    $fresh = $this->channel->fresh();
    expect($fresh->timezone)->toBe('Europe/Warsaw')
        ->and($fresh->posting_goal)->toBe(4)
        ->and($fresh->posting_schedule->toArray())->toEqual(schedulePayload()['posting_schedule']);
});

test('update rejects invalid input and saves nothing', function (array $overrides, string $errorKey) {
    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);

    expect($this->channel->fresh()->timezone)->toBe('UTC');
})->with([
    'bad timezone' => [['timezone' => 'Mars/Base'], 'timezone'],
    'goal too high' => [['posting_goal' => 29], 'posting_goal'],
    'six days' => [['posting_schedule' => array_slice(PostingSchedule::empty()->toArray(), 0, 6)], 'posting_schedule'],
    'bad time' => [['posting_schedule' => array_replace(PostingSchedule::empty()->toArray(), [1 => ['day' => 1, 'enabled' => true, 'times' => ['9:7']]])], 'posting_schedule.1.times.0'],
    'duplicate time' => [['posting_schedule' => array_replace(PostingSchedule::empty()->toArray(), [1 => ['day' => 1, 'enabled' => true, 'times' => ['09:00', '09:00']]])], 'posting_schedule.1.times.0'],
    'five times' => [['posting_schedule' => array_replace(PostingSchedule::empty()->toArray(), [1 => ['day' => 1, 'enabled' => true, 'times' => ['01:00', '02:00', '03:00', '04:00', '05:00']]])], 'posting_schedule.1.times'],
    'duplicate day' => [['posting_schedule' => array_replace(PostingSchedule::empty()->toArray(), [2 => ['day' => 1, 'enabled' => true, 'times' => []]])], 'posting_schedule.2.day'],
]);

test('generate from a goal saves the goal and a matching schedule', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.generate', $this->channel), ['mode' => 'goal', 'goal' => 5])
        ->assertOk()
        ->assertJsonPath('posting_goal', 5);

    expect($this->channel->fresh()->posting_schedule->slotCount())->toBe(5);
});

test('an owner without app access can still generate during onboarding', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.billing.require_card_for_trial', true);

    $this->actingAs($this->user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('app.welcome.persona'));

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.generate', $this->channel), ['mode' => 'goal', 'goal' => 4])
        ->assertOk()
        ->assertJsonPath('posting_goal', 4);

    expect($this->channel->fresh()->posting_schedule->slotCount())->toBe(4);
});

test('generate recommended without a goal uses three', function () {
    $this->channel->update(['posting_goal' => null]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.generate', $this->channel), ['mode' => 'recommended'])
        ->assertOk();

    expect($this->channel->fresh()->posting_schedule->slotCount())->toBe(3);
});

test('copy takes the schedule but not the time zone', function () {
    $source = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'Asia/Tokyo',
        'posting_schedule' => PostingSchedule::empty()->withTime(6, '17:08'),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $source->id])
        ->assertOk();

    $fresh = $this->channel->fresh();
    expect($fresh->posting_schedule->toArray())->toEqual($source->posting_schedule->toArray())
        ->and($fresh->timezone)->toBe('UTC');
});

test('copying from a channel without a schedule is rejected and keeps the target', function () {
    $this->channel->update(['posting_schedule' => PostingSchedule::empty()->withTime(2, '08:00')]);
    $source = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $source->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('from');

    expect($this->channel->fresh()->posting_schedule->slotCount())->toBe(1);
});

test('another workspace channel is not found as target or copy source', function () {
    $foreign = SocialAccount::factory()->linkedin()->create(['posting_schedule' => PostingSchedule::empty()->withTime(0, '10:00')]);

    $this->actingAs($this->user)->get(route('app.channels.settings', $foreign))->assertNotFound();
    $this->actingAs($this->user)->putJson(route('app.channels.posting-schedule.update', $foreign), schedulePayload())->assertNotFound();
    $this->actingAs($this->user)->postJson(route('app.channels.posting-schedule.generate', $foreign), ['mode' => 'goal', 'goal' => 3])->assertNotFound();
    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $foreign->id])
        ->assertNotFound();

    expect($this->channel->fresh()->posting_schedule)->toBeNull()
        ->and($foreign->fresh()->timezone)->toBe('UTC');
});

test('the same time on different days is accepted', function () {
    $schedule = PostingSchedule::empty()->withTime(1, '18:30')->withTime(2, '18:30')->withTime(5, '18:30');

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload(['posting_schedule' => $schedule->toArray()]))
        ->assertOk();

    expect($this->channel->fresh()->posting_schedule->toArray())->toEqual($schedule->toArray());
});

test('a duplicate time within one day is rejected even when other days share it', function () {
    $schedule = array_replace(PostingSchedule::empty()->withTime(2, '09:00')->toArray(), [
        1 => ['day' => 1, 'enabled' => true, 'times' => ['09:00', '10:00', '09:00']],
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload(['posting_schedule' => $schedule]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['posting_schedule.1.times.0', 'posting_schedule.1.times.2'])
        ->assertJsonMissingValidationErrors(['posting_schedule.2.times.0']);
});

test('a busy queue does not fail the saved schedule and keeps the queued times', function () {
    Exceptions::fake();
    $time = now()->addDays(3)->startOfMinute();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => $time,
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $this->channel->id]);
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    $lock->get();

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload())
        ->assertOk();

    $lock->release();

    expect($this->channel->refresh()->posting_goal)->toBe(4)
        ->and($post->refresh()->scheduled_at->equalTo($time))->toBeTrue();
    Exceptions::assertReported(QueueBusyException::class);
});
