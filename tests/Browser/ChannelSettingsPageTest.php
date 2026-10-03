<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;

function waitForChannelSettingsPageTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function channelSettingsPageSetup(?PostingSchedule $schedule = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'posting_goal' => 3,
        'posting_schedule' => $schedule ?? PostingSchedule::empty()->withTime(1, '09:42'),
    ]);

    return [$user->fresh(), $channel];
}

function pickChannelSettingsOption(mixed $page, string $select, string $value): void
{
    $page->click("@{$select}");
    waitForChannelSettingsPageTestId($page, "{$select}-option-{$value}");
    $page->click("@{$select}-option-{$value}");
}

function chooseChannelSettingsTimezone(mixed $page, string $search, string $optionKey): void
{
    waitForChannelSettingsPageTestId($page, 'channel-timezone-trigger');
    $page->click('@channel-timezone-trigger');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-search');
    $page->type('@channel-timezone-search', $search);
    waitForChannelSettingsPageTestId($page, "channel-timezone-option-{$optionKey}");
    $page->click("@channel-timezone-option-{$optionKey}");
}

function waitForChannelSaved(mixed $page, SocialAccount $channel, callable $predicate): void
{
    for ($i = 0; $i < 40; $i++) {
        if ($predicate($channel->fresh())) {
            return;
        }
        $page->script('new Promise((r) => setTimeout(r, 100))');
    }
}

test('adding a weekday time, removing one and toggling a day persist', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-day-1-time-0942');

    pickChannelSettingsOption($page, 'schedule-add-target', 'weekdays');
    pickChannelSettingsOption($page, 'schedule-add-hour', '18');
    pickChannelSettingsOption($page, 'schedule-add-minute', '30');
    $page->click('@schedule-add-submit');
    waitForChannelSettingsPageTestId($page, 'schedule-day-5-time-1830');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->slotCount() === 6);

    $page->click('@schedule-day-1-time-0942-remove');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->slotCount() === 5);

    $page->click('@schedule-day-3-toggle');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->days()[3]['enabled'] === false);

    $fresh = $channel->fresh()->posting_schedule;
    expect($fresh->days()[1]['times'])->toBe(['18:30'])
        ->and($fresh->days()[3]['enabled'])->toBeFalse()
        ->and($fresh->days()[3]['times'])->toBe(['18:30'])
        ->and($fresh->days()[0]['times'])->toBe([]);
    $page->assertNoJavaScriptErrors();
});

test('the time zone and goal save from the page', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-submit');
    $page->click('@channel-timezone-confirm-submit');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->timezone === 'Europe/Warsaw');

    $page->click('@channel-goal-increase');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_goal === 4);

    expect($channel->fresh()->timezone)->toBe('Europe/Warsaw')
        ->and($channel->fresh()->posting_goal)->toBe(4)
        ->and($channel->fresh()->posting_schedule->slotCount())->toBe(1);
    $page->assertNoJavaScriptErrors();
});

test('clear all, generate from goal and copy from another channel', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $other = SocialAccount::factory()->x()->create([
        'workspace_id' => $channel->workspace_id,
        'posting_schedule' => PostingSchedule::empty()->withTime(6, '17:08'),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-clear');
    $page->click('@schedule-clear');
    waitForChannelSettingsPageTestId($page, 'schedule-clear-confirm');
    $page->click('@schedule-clear-confirm');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->slotCount() === 0);

    $page->click('@schedule-generate');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-goal');
    $page->click('@schedule-generate-goal');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-confirm');
    $page->click('@schedule-generate-confirm');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->slotCount() === 3);

    $page->click('@schedule-generate');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-copy');
    $page->click('@schedule-generate-copy');
    waitForChannelSettingsPageTestId($page, "schedule-generate-copy-{$other->id}");
    $page->click("@schedule-generate-copy-{$other->id}");
    waitForChannelSettingsPageTestId($page, 'schedule-generate-confirm');
    $page->click('@schedule-generate-confirm');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->days()[6]['times'] === ['17:08']);

    expect($channel->fresh()->posting_schedule->toArray())->toEqual($other->posting_schedule->toArray());
    $page->assertNoJavaScriptErrors();
});

test('a fifth time on a day is blocked', function () {
    [$user, $channel] = channelSettingsPageSetup(
        PostingSchedule::empty()->withTime(2, '08:00')->withTime(2, '09:00')->withTime(2, '10:00')->withTime(2, '11:00'),
    );
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-add-target');
    pickChannelSettingsOption($page, 'schedule-add-target', '2');
    waitForChannelSettingsPageTestId($page, 'schedule-add-limit');

    $page->assertVisible('@schedule-add-limit')->assertMissing('@schedule-add-submit');
    expect($channel->fresh()->posting_schedule->days()[2]['times'])->toHaveCount(4);
    $page->assertNoJavaScriptErrors();
});

test('a channel without a schedule shows the empty state', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $channel->update(['posting_schedule' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-empty');

    $page->assertVisible('@schedule-empty')->assertVisible('@schedule-generate')->assertNoJavaScriptErrors();
});

test('two rapid removes on different days both persist', function () {
    [$user, $channel] = channelSettingsPageSetup(
        PostingSchedule::empty()->withTime(1, '09:00')->withTime(2, '10:00')->withTime(3, '11:00'),
    );
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-day-1-time-0900-remove');
    $page->click('@schedule-day-1-time-0900-remove')->click('@schedule-day-2-time-1000-remove');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->posting_schedule->slotCount() === 1);
    $page->script('new Promise((r) => setTimeout(r, 500))');

    expect($channel->fresh()->posting_schedule->slotCount())->toBe(1)
        ->and($channel->fresh()->posting_schedule->days()[3]['times'])->toBe(['11:00']);
    $page->assertMissing('@schedule-day-1-time-0900')->assertMissing('@schedule-day-2-time-1000')->assertVisible('@schedule-day-3-time-1100');
    $page->assertNoJavaScriptErrors();
});

test('meet my posting goal is disabled with a hint once the goal is met', function () {
    [$user, $channel] = channelSettingsPageSetup(
        PostingSchedule::empty()->withTime(1, '09:00')->withTime(2, '10:00')->withTime(3, '11:00'),
    );
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-generate');
    $page->click('@schedule-generate');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-goal');

    $page->assertAttribute('@schedule-generate-goal', 'data-disabled', '')
        ->assertAttribute('@schedule-generate-copy', 'data-disabled', '')
        ->assertScript('getComputedStyle(document.querySelector("[data-testid=schedule-generate-goal]")).opacity', '1')
        ->assertScript('(() => { const toggle = document.querySelector("[data-testid=schedule-day-1-toggle]").getBoundingClientRect(); return `${toggle.width}x${toggle.height}`; })()', '30x16');

    $page->hover('@schedule-generate-goal-hint');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-goal-tooltip');

    $page->assertVisible('@schedule-generate-goal-tooltip')
        ->assertMissing('@schedule-generate-confirm');
    expect($channel->fresh()->posting_schedule->slotCount())->toBe(3);
    $page->assertNoJavaScriptErrors();
});

test('meet my posting goal stays enabled below the goal', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-generate');
    $page->click('@schedule-generate');
    waitForChannelSettingsPageTestId($page, 'schedule-generate-goal');

    $page->assertAttributeMissing('@schedule-generate-goal', 'data-disabled')->assertNoJavaScriptErrors();
});

test('the schedule grid follows the week start and the 12-hour clock', function () {
    [$user, $channel] = channelSettingsPageSetup(PostingSchedule::empty()->withTime(0, '14:30'));
    $user->update(['week_starts_on' => WeekStart::Sunday, 'time_format' => TimeFormat::TwelveHour]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-day-0-time-1430-meridiem');

    $page->assertScript("document.querySelector('[data-testid^=\"schedule-day-\"]').dataset.testid", 'schedule-day-0')
        ->assertSeeIn('@schedule-day-0-time-1430-hour', '2')
        ->assertSeeIn('@schedule-day-0-time-1430-meridiem', 'PM')
        ->assertNoJavaScriptErrors();
});

test('the schedule grid starts on Monday on a 24-hour clock by default', function () {
    [$user, $channel] = channelSettingsPageSetup(PostingSchedule::empty()->withTime(0, '14:30'));
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'schedule-day-0-time-1430-hour');

    $page->assertScript("document.querySelector('[data-testid^=\"schedule-day-\"]').dataset.testid", 'schedule-day-1')
        ->assertSeeIn('@schedule-day-0-time-1430-hour', '14')
        ->assertMissing('@schedule-day-0-time-1430-meridiem')
        ->assertNoJavaScriptErrors();
});

test('the channel time zone picker suggests the browser time zone first', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    waitForChannelSettingsPageTestId($page, 'channel-timezone-trigger');
    $page->click('@channel-timezone-trigger');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-detected');

    $page->assertVisible('@channel-timezone-detected')->assertNoJavaScriptErrors();
});

test('changing the time zone asks first, cancel keeps the old zone', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-dialog');

    $order = $page->script('[...document.querySelectorAll(\'[data-testid="channel-timezone-confirm-dialog"] button[data-testid]\')].map((button) => button.dataset.testid)');
    expect($order)->toBe(['channel-timezone-confirm-cancel', 'channel-timezone-confirm-submit']);
    $page->assertSeeIn('@channel-timezone-confirm-dialog', 'Europe/Warsaw')
        ->click('@channel-timezone-confirm-cancel');
    $page->script('(async () => { for (let i = 0; i < 100; i++) { if (!document.querySelector(\'[data-testid="channel-timezone-confirm-dialog"]\')) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    $page->assertSeeIn('@channel-timezone-trigger', 'UTC')->assertNoJavaScriptErrors();
    expect($channel->fresh()->timezone)->toBe('UTC');
});

test('escape dismisses the confirmation and saves nothing', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-cancel');
    $page->keys('@channel-timezone-confirm-cancel', 'Escape');
    $page->script('(async () => { for (let i = 0; i < 100; i++) { if (!document.querySelector(\'[data-testid="channel-timezone-confirm-dialog"]\')) return; await new Promise((r) => setTimeout(r, 50)); } })();');

    chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-dialog');
    $page->assertVisible('@channel-timezone-confirm-dialog')->assertNoJavaScriptErrors();
    expect($channel->fresh()->timezone)->toBe('UTC');
});

test('confirming the time zone saves it and reflows the queue into the new zone slots', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id, 'platform' => $channel->platform, 'enabled' => true]);
    ReflowChannelQueue::handle($channel, $post, QueuePosition::Next);
    $this->actingAs($user);

    $page = visit(route('app.channels.settings', $channel));
    chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
    waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-submit');
    $page->click('@channel-timezone-confirm-submit');
    waitForChannelSaved($page, $channel, fn (SocialAccount $c): bool => $c->timezone === 'Europe/Warsaw');

    for ($i = 0; $i < 40 && $post->fresh()->scheduled_at->setTimezone('Europe/Warsaw')->format('H:i') !== '09:42'; $i++) {
        $page->script('new Promise((r) => setTimeout(r, 100))');
    }

    $local = $post->fresh()->scheduled_at->setTimezone('Europe/Warsaw');
    expect($local->format('H:i'))->toBe('09:42')
        ->and($local->dayOfWeek)->toBe(1);
    $page->assertNoJavaScriptErrors();
});

test('the time zone confirmation buttons fit on one line in every language', function () {
    [$user, $channel] = channelSettingsPageSetup();
    $this->actingAs($user);
    $problems = [];

    foreach (Locale::cases() as $locale) {
        $user->update(['locale' => $locale]);
        $page = visit(route('app.channels.settings', $channel));
        chooseChannelSettingsTimezone($page, 'Warsaw', 'Europe-Warsaw');
        waitForChannelSettingsPageTestId($page, 'channel-timezone-confirm-submit');

        foreach (['channel-timezone-confirm-cancel', 'channel-timezone-confirm-submit'] as $button) {
            $lines = $page->script("(() => { const range = document.createRange(); range.selectNodeContents(document.querySelector('[data-testid=\"{$button}\"]')); return new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top))).size; })()");

            if ($lines > 1) {
                $problems[] = "{$locale->value}: {$button}";
            }
        }
    }

    expect($problems)->toBe([]);
});
