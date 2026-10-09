<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Cache;

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

test('an owner without app access cannot update or copy a schedule', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.billing.require_card_for_trial', true);
    $source = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'posting_schedule' => PostingSchedule::empty()->withTime(0, '10:00'),
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload())
        ->assertRedirect(route('app.welcome.persona'));

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $source->id])
        ->assertRedirect(route('app.welcome.persona'));

    expect($this->channel->fresh()->posting_schedule)->toBeNull();
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

test('a busy queue fails the schedule update, saves nothing and keeps the queued times', function () {
    $time = now()->addDays(3)->startOfMinute();
    $post = Post::factory()->forAccount($this->channel)->create([
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => $time,
    ]);
    $before = $this->channel->only(['timezone', 'posting_goal']);
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    $lock->get();

    try {
        $this->actingAs($this->user)
            ->putJson(route('app.channels.posting-schedule.update', $this->channel), schedulePayload())
            ->assertConflict()
            ->assertJson(['message' => __('posts.errors.queue_busy')]);
    } finally {
        $lock->release();
    }

    expect($this->channel->refresh()->only(['timezone', 'posting_goal']))->toBe($before)
        ->and($post->refresh()->scheduled_at->equalTo($time))->toBeTrue();
});

test('a busy queue fails generate and copy without touching the schedule', function () {
    $schedule = PostingSchedule::empty()->withTime(2, '08:00');
    $this->channel->update(['posting_schedule' => $schedule, 'posting_goal' => 1]);
    $source = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'posting_schedule' => PostingSchedule::empty()->withTime(0, '10:00'),
    ]);
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    $lock->get();

    try {
        $this->actingAs($this->user)
            ->postJson(route('app.channels.posting-schedule.generate', $this->channel), ['mode' => 'goal', 'goal' => 4])
            ->assertConflict();

        $this->actingAs($this->user)
            ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $source->id])
            ->assertConflict();
    } finally {
        $lock->release();
    }

    $fresh = $this->channel->refresh();
    expect($fresh->posting_goal)->toBe(1)
        ->and($fresh->posting_schedule->toArray())->toEqual($schedule->toArray());
});

test('a time zone change saves the zone and moves queued posts together', function () {
    $this->travelTo(now()->startOfWeek()->addWeek()->setTime(6, 0));
    $schedule = PostingSchedule::empty()->withTime(3, '09:00');
    $this->channel->update(['timezone' => 'UTC', 'posting_schedule' => $schedule]);
    $post = Post::factory()->forAccount($this->channel)->create([
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => now()->next('Wednesday')->setTime(9, 0),
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 1,
            'posting_schedule' => $schedule->toArray(),
        ])
        ->assertOk();

    expect($this->channel->refresh()->timezone)->toBe('America/Sao_Paulo')
        ->and($post->refresh()->scheduled_at->setTimezone('America/Sao_Paulo')->format('D H:i'))->toBe('Wed 09:00');
});

test('members without admin rights cannot change, generate or copy a schedule', function (string $access, string $routeName, string $method, Closure $payload) {
    $member = workspaceMember($this->workspace, $access);
    $source = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'posting_schedule' => PostingSchedule::empty()->withTime(0, '10:00'),
    ]);
    $before = $this->channel->fresh()->only(['timezone', 'posting_goal']);
    $beforeSchedule = $this->channel->fresh()->posting_schedule?->toArray();

    $this->actingAs($member)
        ->{$method}(route($routeName, $this->channel), $payload($source))
        ->assertForbidden();

    $fresh = $this->channel->fresh();
    expect($fresh->only(['timezone', 'posting_goal']))->toBe($before)
        ->and($fresh->posting_schedule?->toArray())->toEqual($beforeSchedule);
})->with(['publishes directly' => 'member', 'needs approval' => 'approval'])->with([
    'update' => ['app.channels.posting-schedule.update', 'putJson', fn (SocialAccount $source): array => schedulePayload()],
    'generate' => ['app.channels.posting-schedule.generate', 'postJson', fn (SocialAccount $source): array => ['mode' => 'goal', 'goal' => 5]],
    'copy' => ['app.channels.posting-schedule.copy', 'postJson', fn (SocialAccount $source): array => ['from' => $source->id]],
]);

test('a time zone change keeps the instant of a post with a custom time', function () {
    $this->travelTo(now()->startOfWeek()->addWeek()->setTime(6, 0));
    $schedule = PostingSchedule::empty()->withTime(3, '09:00');
    $this->channel->update(['timezone' => 'UTC', 'posting_schedule' => $schedule]);
    $instant = now()->next('Thursday')->setTime(14, 30);
    $post = Post::factory()->forAccount($this->channel)->create([
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => $instant,
    ]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'Asia/Tokyo',
            'posting_goal' => 1,
            'posting_schedule' => $schedule->toArray(),
        ])
        ->assertOk();

    expect($post->refresh()->scheduled_at->equalTo($instant))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
});
