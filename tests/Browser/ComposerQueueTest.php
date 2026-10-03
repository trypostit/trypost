<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\User\DefaultPostAction;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

/**
 * Poll from the page (never sleep()) until the composer dialog is open and
 * still and the given element is laid out.
 */
function waitForComposerQueueTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForComposerQueueClosed(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function waitForComposerQueuePageTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: Workspace}
 */
function composerQueueWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user, $workspace];
}

/**
 * Every day at one hour roughly six hours away, so the first slot never falls
 * inside the test run.
 */
function composerQueueSchedule(): PostingSchedule
{
    $time = now()->utc()->addHours(6)->format('H:00');
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, $time);
    }

    return $schedule;
}

function composerQueueChannel(Workspace $workspace, ?PostingSchedule $schedule): SocialAccount
{
    return SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);
}

/**
 * @return list<CarbonImmutable>
 */
function composerQueueSlots(SocialAccount $channel, int $count): array
{
    return $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, $count);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function composerQueueSeedPost(User $user, Workspace $workspace, SocialAccount $channel, array $overrides = []): Post
{
    return CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Already queued',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->first();
}

function composerQueueOpenWith(mixed $page, SocialAccount $channel): void
{
    waitForComposerQueueTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}")
        ->fill("@composer-caption-{$channel->id}", 'Queued from the composer');
}

test('a channel with slots defaults to the queue and takes its first slot', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);

    waitForComposerQueueTestId($page, 'composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('next')
        ->and($page->script('document.querySelector("[data-testid=composer-submit]").textContent.trim()'))->toBe('Add to queue');

    $page->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-next')
        ->assertVisible('@composer-schedule-top')
        ->assertVisible('@composer-schedule-now')
        ->assertVisible('@composer-schedule-custom')
        ->assertMissing('@composer-queue-hint')
        ->click('@composer-schedule-next')
        ->click('@composer-submit');
    waitForComposerQueueClosed($page);

    $post = Post::query()->where('workspace_id', $workspace->id)->sole();

    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->scheduled_at->toIso8601String())->toBe(composerQueueSlots($channel, 1)[0]->toIso8601String());

    $page->assertNoJavaScriptErrors();
});

test('the default posting action preference picks the composer initial mode', function (DefaultPostAction $action, string $mode) {
    [$user, $workspace] = composerQueueWorkspace();
    $user->update(['default_post_action' => $action]);
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);
    waitForComposerQueueTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe($mode);
    $page->assertNoJavaScriptErrors();
})->with([
    'prioritize' => [DefaultPostAction::Top, 'top'],
    'share now' => [DefaultPostAction::Now, 'now'],
    'set date and time' => [DefaultPostAction::Custom, 'custom'],
]);

test('a queue default falls back when the channel has no posting times', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $user->update(['default_post_action' => DefaultPostAction::Top]);
    $channel = composerQueueChannel($workspace, null);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);
    waitForComposerQueueTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('now');
    $page->assertNoJavaScriptErrors();
});

test('a channel without slots disables the queue options, names it in a tooltip and links to its posting times', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, null);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);

    $page->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-now')
        ->assertVisible('@composer-schedule-next')
        ->assertVisible('@composer-schedule-top')
        ->assertVisible('@composer-queue-hint')
        ->assertVisible('@composer-queue-manage-slots');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-next]").disabled'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-top]").disabled'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-now]").disabled'))->toBeFalse();

    $page->hover('@composer-schedule-row-next');
    waitForComposerQueuePageTestId($page, 'composer-schedule-blocked-next');
    $name = $channel->display_label ?: $channel->display_name;
    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-blocked-next]").textContent')))
        ->toStartWith("No posting times for {$name}. Add posting times to use the queue.");

    $page->hover('@composer-schedule-custom')->hover('@composer-schedule-default-next');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.body.innerText.includes('Set as default posting action: Next available.')
                    && document.querySelectorAll('[data-testid^="composer-schedule-blocked-"]').length === 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
    $page->assertSee('Set as default posting action: Next available.');
    expect($page->script('document.querySelectorAll("[data-testid^=composer-schedule-blocked-]").length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('rapid star clicks keep only the last default posting action', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);
    $page->click('@composer-schedule-trigger');
    waitForComposerQueuePageTestId($page, 'composer-schedule-default-now');

    $page->script(<<<'JS'
        (() => {
            window.__preferences = { started: 0, settled: 0, last: null, events: [] };
            const { send } = XMLHttpRequest.prototype;
            XMLHttpRequest.prototype.send = function (body) {
                if (String(body ?? '').includes('default_post_action')) {
                    const action = JSON.parse(body).default_post_action;
                    window.__preferences.started++;
                    window.__preferences.last = action;
                    window.__preferences.events.push(`start:${action}`);
                    this.addEventListener('readystatechange', () => {
                        if (this.readyState !== XMLHttpRequest.DONE) return;
                        window.__preferences.settled++;
                        window.__preferences.events.push(`end:${action}`);
                    });
                }
                return send.call(this, body);
            };
            document.querySelector('[data-testid="composer-schedule-default-now"]').click();
            document.querySelector('[data-testid="composer-schedule-default-top"]').click();
        })();
    JS);
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const preferences = window.__preferences;
                if (preferences.last === 'top' && preferences.settled === preferences.started) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    expect($page->script('window.__preferences.events'))->toBe(['start:now', 'end:now', 'start:top', 'end:top'])
        ->and($page->script('window.__preferences.last'))->toBe('top')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-default-top]").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-default-now]").getAttribute("aria-pressed")'))->toBe('false')
        ->and($user->refresh()->default_post_action)->toBe(DefaultPostAction::Top);
    $page->assertNoJavaScriptErrors();
});

test('the schedule menu lists all four options before any channel is selected and keeps the choice', function () {
    [$user] = composerQueueWorkspace();
    $user->update(['default_post_action' => DefaultPostAction::Now]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerQueueTestId($page, 'composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    waitForComposerQueuePageTestId($page, 'composer-schedule-custom');

    $order = $page->script('[...document.querySelectorAll("[data-testid^=composer-schedule-row-]")].map((row) => row.dataset.testid.replace("composer-schedule-row-", ""))');
    $disabled = $page->script('[...document.querySelectorAll("[data-testid^=composer-schedule-row-]")].map((row) => row.querySelector("button").disabled)');
    expect($order)->toBe(['next', 'top', 'now', 'custom'])
        ->and($disabled)->toBe([false, false, false, false]);

    $page->click('@composer-schedule-top');
    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent')))->toBe('Prioritize');
    $page->assertNoJavaScriptErrors();
});

test('the star saves the default posting action without selecting the option', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);
    $page->click('@composer-schedule-trigger');
    waitForComposerQueuePageTestId($page, 'composer-schedule-default-now');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-default-next]").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-next]").getAttribute("aria-pressed")'))->toBe('true');

    expect($page->script('getComputedStyle(document.querySelector("[data-testid=composer-schedule-default-now]")).opacity'))->toBe('0')
        ->and($page->script('getComputedStyle(document.querySelector("[data-testid=composer-schedule-default-next]")).opacity'))->toBe('1')
        ->and($page->script('[getComputedStyle(document.querySelector("[data-testid=composer-schedule-default-now]")).width, getComputedStyle(document.querySelector("[data-testid=composer-schedule-default-now] svg")).width]'))->toBe(['24px', '16px']);

    $page->hover('@composer-schedule-default-now')
        ->assertSee('Set as default posting action: Now.')
        ->click('@composer-schedule-default-now');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (performance.getEntriesByType('resource').some((entry) => entry.name.includes('/settings/preferences'))) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    expect($page->script('document.querySelector("[data-testid=composer-schedule-default-now]").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-default-next]").getAttribute("aria-pressed")'))->toBe('false')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-next]").getAttribute("aria-pressed")'))->toBe('true')
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-now]").getAttribute("aria-pressed")'))->toBe('false')
        ->and($page->script('Boolean(document.querySelector("[data-testid=composer-schedule-now]"))'))->toBeTrue();

    expect($user->refresh()->default_post_action)->toBe(DefaultPostAction::Now);
    $page->assertNoJavaScriptErrors();

    visit(route('app.settings.preferences'))
        ->assertSeeIn('@preferences-default-post-action-trigger', 'Now')
        ->assertNoJavaScriptErrors();
});

test('prioritize puts the new post first and moves the queued one back', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $existing = composerQueueSeedPost($user, $workspace, $channel);
    [$first, $second] = composerQueueSlots($channel, 2);
    expect($existing->scheduled_at->toIso8601String())->toBe($first->toIso8601String());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);

    $page->click('@composer-schedule-trigger')
        ->click('@composer-schedule-top');

    waitForComposerQueueTestId($page, 'composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('top');

    $page->click('@composer-submit');
    waitForComposerQueueClosed($page);

    $created = Post::query()->where('workspace_id', $workspace->id)->whereKeyNot($existing->id)->sole();

    expect($created->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($created->scheduled_at->toIso8601String())->toBe($first->toIso8601String())
        ->and($existing->refresh()->scheduled_at->toIso8601String())->toBe($second->toIso8601String());

    $page->assertNoJavaScriptErrors();
});

test('the queue list marks only custom-time posts', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $queued = composerQueueSeedPost($user, $workspace, $channel);
    $custom = composerQueueSeedPost($user, $workspace, $channel, [
        'queue' => null,
        'scheduled_at' => now()->addDays(3)->toIso8601String(),
        'content' => 'Custom time',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForComposerQueuePageTestId($page, "post-schedule-mode-{$custom->id}");

    $page->assertVisible("@post-card-{$queued->id}")
        ->assertMissing("@post-schedule-mode-{$queued->id}")
        ->assertAttribute("@post-schedule-mode-{$custom->id}", 'data-mode', 'custom')
        ->assertNoJavaScriptErrors();
});

test('editing a queued post to a custom time switches its marker to custom', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $queued = composerQueueSeedPost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $queued));
    waitForComposerQueueTestId($page, 'composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('next');

    $page->click('@composer-schedule-trigger')
        ->click('@composer-schedule-custom')
        ->assertVisible('@composer-schedule-picker')
        ->click('@composer-schedule-done');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('custom');

    $page->click('@composer-submit');

    for ($attempt = 0; $attempt < 50 && $queued->refresh()->schedule_mode !== ScheduleMode::Custom; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect($queued->refresh()->schedule_mode)->toBe(ScheduleMode::Custom);

    $page = visit(route('app.posts.index'));
    waitForComposerQueuePageTestId($page, "post-schedule-mode-{$queued->id}");

    $page->assertAttribute("@post-schedule-mode-{$queued->id}", 'data-mode', 'custom')
        ->assertNoJavaScriptErrors();
});

test('recovering an empty-target draft into the queue confirms it with a toast', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $legacy = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Recover into the queue',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $legacy));
    waitForComposerQueueTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}");
    waitForComposerQueueTestId($page, 'composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('next');

    $page->click('@composer-submit');
    waitForComposerQueueClosed($page);
    waitForComposerQueuePageTestId($page, 'queue-added-toast');

    $recovered = Post::query()->where('workspace_id', $workspace->id)->sole();

    expect($recovered->id)->not->toBe($legacy->id)
        ->and($recovered->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($recovered->scheduled_at->toIso8601String())->toBe(composerQueueSlots($channel, 1)[0]->toIso8601String())
        ->and($page->script('document.querySelector("[data-testid=queue-added-toast]")?.textContent.trim() ?? ""'))->toContain('Added to queue');

    $page->assertNoJavaScriptErrors();
});

/**
 * Open the composer's date and time picker from the When menu.
 */
function composerScheduleOpenPicker(mixed $page): void
{
    waitForComposerQueueTestId($page, 'composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    waitForComposerQueuePageTestId($page, 'composer-schedule-custom');
    $page->click('@composer-schedule-custom');
    waitForComposerQueuePageTestId($page, 'composer-schedule-picker');
}

/**
 * Click the first selectable day after today in the picker, moving to the next
 * month when today is the last selectable day of the shown one.
 */
function composerSchedulePickFutureDay(mixed $page): string
{
    $query = '[...document.querySelectorAll("[data-testid^=composer-schedule-day-]")].find((day) => !day.hasAttribute("data-disabled") && !day.hasAttribute("data-outside-view") && !day.hasAttribute("data-zone-today"))?.dataset.testid.replace("composer-schedule-day-", "") ?? null';
    $day = $page->script($query);

    if ($day === null) {
        $page->click('@composer-schedule-calendar-next');
        $day = $page->script($query);
    }

    $page->click("@composer-schedule-day-{$day}");

    return $day;
}

function composerScheduleExpectedLabel(string $day, string $time, string $userTimezone): string
{
    $date = CarbonImmutable::parse($day);

    return $date->format($date->year === now($userTimezone)->year ? 'M j' : 'M j, Y').", {$time}";
}

test('the schedule trigger points up and its icon follows the selected mode', function () {
    [$user, $workspace] = composerQueueWorkspace();
    $channel = composerQueueChannel($workspace, composerQueueSchedule());
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerQueueOpenWith($page, $channel);

    $icon = 'document.querySelector("[data-testid=composer-schedule-trigger-icon]")';
    expect($page->script("{$icon}.dataset.icon"))->toBe('calendar-clock')
        ->and($page->script("{$icon}.classList.contains('tabler-icon-calendar-clock')"))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=composer-schedule-trigger-chevron]").classList.contains("tabler-icon-chevron-up")'))->toBeTrue();

    $page->click('@composer-schedule-trigger')->click('@composer-schedule-top');
    expect($page->script("{$icon}.dataset.icon"))->toBe('calendar-clock');

    $page->click('@composer-schedule-trigger')->click('@composer-schedule-now');
    expect($page->script("{$icon}.dataset.icon"))->toBe('send')
        ->and($page->script("{$icon}.classList.contains('tabler-icon-send')"))->toBeTrue();

    composerScheduleOpenPicker($page);
    composerSchedulePickFutureDay($page);
    $page->click('@composer-schedule-done');
    expect($page->script("{$icon}.dataset.icon"))->toBe('pin')
        ->and($page->script("{$icon}.classList.contains('tabler-icon-pin')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('picking a day and typing a time schedules a custom date with the user time format', function () {
    [$user] = composerQueueWorkspace();
    $user->update(['time_format' => TimeFormat::TwelveHour, 'timezone' => 'Asia/Tokyo']);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerScheduleOpenPicker($page);

    expect($page->script('document.querySelector("[data-testid=composer-schedule-timezone]").textContent.trim()'))->toBe('Asia/Tokyo');

    $day = composerSchedulePickFutureDay($page);
    $page->fill('@composer-schedule-time-input', '1715')
        ->click('@composer-schedule-done')
        ->assertMissing('@composer-schedule-picker');

    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent')))
        ->toBe(composerScheduleExpectedLabel($day, '5:15 PM', $user->timezone));
    $page->assertNoJavaScriptErrors();
});

test('the time input opens a fifteen minute list that sets the time', function () {
    [$user] = composerQueueWorkspace();
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerScheduleOpenPicker($page);
    $day = composerSchedulePickFutureDay($page);

    $page->assertMissing('@composer-schedule-time-list')
        ->click('@composer-schedule-time-input');
    waitForComposerQueuePageTestId($page, 'composer-schedule-time-list');

    $options = $page->script('[...document.querySelectorAll("[data-testid^=composer-schedule-time-option-]")].map((option) => option.textContent.trim())');
    expect($options)->toHaveCount(96)
        ->and(array_slice($options, 0, 3))->toBe(['00:00', '00:15', '00:30']);

    $page->click('@composer-schedule-time-option-0945')
        ->assertMissing('@composer-schedule-time-list')
        ->assertValue('@composer-schedule-time-input', '09:45')
        ->click('@composer-schedule-time-input');
    waitForComposerQueuePageTestId($page, 'composer-schedule-time-list');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-time-option-0945]").getAttribute("aria-selected")'))->toBe('true');

    $page->click('@composer-schedule-done');

    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent')))
        ->toBe(composerScheduleExpectedLabel($day, '09:45', $user->timezone));
    $page->assertNoJavaScriptErrors();
});

test('more posting actions returns from the picker to the when menu', function () {
    [$user] = composerQueueWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerScheduleOpenPicker($page);

    $page->assertMissing('@composer-schedule-row-next')
        ->click('@composer-schedule-more-actions')
        ->assertMissing('@composer-schedule-picker')
        ->assertVisible('@composer-schedule-row-next')
        ->assertVisible('@composer-schedule-custom')
        ->assertNoJavaScriptErrors();
});

test('the picker calendar starts the week on the user preference', function (WeekStart $weekStart, string $firstWeekday, int $dayOfWeek) {
    [$user] = composerQueueWorkspace();
    $user->update(['week_starts_on' => $weekStart]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    composerScheduleOpenPicker($page);

    $firstDay = $page->script('document.querySelector("[data-testid^=composer-schedule-day-]").dataset.testid.replace("composer-schedule-day-", "")');

    expect($page->script('document.querySelector("[data-testid=composer-schedule-weekday]").textContent.trim()'))->toBe($firstWeekday)
        ->and(CarbonImmutable::parse($firstDay)->dayOfWeek)->toBe($dayOfWeek);
    $page->assertNoJavaScriptErrors();
})->with([
    'sunday' => [WeekStart::Sunday, 'S', 0],
    'monday' => [WeekStart::Monday, 'M', 1],
]);
