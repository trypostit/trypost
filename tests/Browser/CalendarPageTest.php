<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\TimeFormat;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;

function waitForCalendarTestId(mixed $page, string $testId): void
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
 * @return array{0: User, 1: SocialAccount, 2: SocialAccount}
 */
function calendarPageSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [
        $user,
        SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']),
        SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']),
    ];
}

function waitForCalendarCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function calendarPagePost(SocialAccount $account, ?CarbonInterface $at, PostStatus $status = PostStatus::Scheduled): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->workspace->user_id,
        'status' => $status,
        'scheduled_at' => $at,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    return $post;
}

function calendarChipSlot(mixed $page, Post $post): ?string
{
    return $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]')?.closest('[data-testid^=\"calendar-slot-\"]')?.dataset.testid ?? null");
}

test('the week grid places posts in their hour row, side by side when they share it', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);
    $dayKey = $day->format('Y-m-d');

    $first = calendarPagePost($linkedin, $day->copy()->setTime(14, 10));
    $second = calendarPagePost($x, $day->copy()->setTime(14, 30));
    $morning = calendarPagePost($x, $day->copy()->setTime(9, 0));

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$first->id}");

    expect(calendarChipSlot($page, $first))->toBe("calendar-slot-{$dayKey}-14")
        ->and(calendarChipSlot($page, $second))->toBe("calendar-slot-{$dayKey}-14")
        ->and(calendarChipSlot($page, $morning))->toBe("calendar-slot-{$dayKey}-09");

    $layout = $page->script(<<<JS
        (() => {
            const box = (id) => document.querySelector('[data-testid="calendar-post-' + id + '"]').getBoundingClientRect();
            const slot = document.querySelector('[data-testid="calendar-slot-{$dayKey}-14"]').getBoundingClientRect();
            const midnight = document.querySelector('[data-testid="calendar-slot-{$dayKey}-00"]').getBoundingClientRect();
            const first = box('{$first->id}');
            const second = box('{$second->id}');

            return {
                sameRow: Math.abs(first.top - second.top) < 1,
                sideBySide: second.left >= first.right,
                hourOffset: Math.round(slot.top - midnight.top),
                inSlot: first.top >= slot.top && first.bottom <= slot.bottom,
            };
        })()
    JS);

    expect($layout)->toBe([
        'sameRow' => true,
        'sideBySide' => true,
        'hourOffset' => 14 * 106,
        'inSlot' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('the week grid has no now line and scrolls to the current hour', function () {
    [$user] = calendarPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']));
    waitForCalendarTestId($page, 'calendar-time-grid');

    $page->assertMissing('@calendar-now-line')
        ->assertScript(
            '(() => { const grid = document.querySelector(\'[data-testid="calendar-time-grid"]\'); return grid.scrollTop === Math.min('.(now('UTC')->hour * 106).', grid.scrollHeight - grid.clientHeight); })()',
            true,
        )
        ->assertNoJavaScriptErrors();
});

test('the calendar honours the channels filter and the display time zone', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);

    $linkedinPost = calendarPagePost($linkedin, $day->copy()->setTime(14, 30));
    $xPost = calendarPagePost($x, $day->copy()->setTime(14, 30));

    $this->actingAs($user);

    $page = visit(route('app.calendar', [
        'view' => 'week',
        'week' => $weekStart->format('Y-m-d'),
        'channels' => [$x->id],
        'tz' => 'Asia/Tokyo',
    ]));
    waitForCalendarTestId($page, "calendar-post-{$xPost->id}");

    $page->assertMissing("@calendar-post-{$linkedinPost->id}")
        ->assertSeeIn('@publish-timezone-select', 'Tokyo');

    expect(calendarChipSlot($page, $xPost))->toBe("calendar-slot-{$day->format('Y-m-d')}-23");

    $listHref = $page->script('decodeURIComponent(document.querySelector(\'[data-testid="schedule-view-list"]\').getAttribute("href"))');

    expect($listHref)->toContain("channels[]={$x->id}")
        ->toContain('tz=Asia/Tokyo');

    $page->assertNoJavaScriptErrors();
});

test('the channel calendar is scoped to the channel and links back to its list', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);

    $linkedinPost = calendarPagePost($linkedin, $day->copy()->setTime(10, 0));
    $xPost = calendarPagePost($x, $day->copy()->setTime(10, 0));

    $this->actingAs($user);

    $page = visit(route('app.channels.calendar', ['account' => $linkedin, 'view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$linkedinPost->id}");

    $page->assertMissing("@calendar-post-{$xPost->id}")
        ->assertSeeIn('@header-title', $linkedin->display_name);

    expect($page->script('new URL(document.querySelector(\'[data-testid="schedule-view-list"]\').href).pathname'))
        ->toBe(parse_url(route('app.channels.publish', $linkedin), PHP_URL_PATH));

    $page->assertNoJavaScriptErrors();
});

test('the view menu offers only week and month', function () {
    [$user] = calendarPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month']));
    waitForCalendarTestId($page, 'calendar-view-trigger');
    $page->click('@calendar-view-trigger');
    waitForCalendarTestId($page, 'calendar-view-week');

    $page->assertVisible('@calendar-view-week')
        ->assertVisible('@calendar-view-month')
        ->assertMissing('@calendar-view-day')
        ->assertNoJavaScriptErrors();
});

test('an overflowing week hour expands in place', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);
    $dayKey = $day->format('Y-m-d');

    $posts = collect(range(0, 3))->map(fn (int $minute): Post => calendarPagePost($minute % 2 ? $x : $linkedin, $day->copy()->setTime(11, $minute * 10)));

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-more-{$dayKey}-11");

    $page->assertSeeIn("@calendar-more-{$dayKey}-11", '+2')
        ->assertMissing("@calendar-post-{$posts[3]->id}")
        ->click("@calendar-more-{$dayKey}-11");
    waitForCalendarTestId($page, "calendar-post-{$posts[3]->id}");

    $page->assertVisible("@calendar-post-{$posts[2]->id}")
        ->assertVisible("@calendar-post-{$posts[3]->id}")
        ->assertMissing("@calendar-more-{$dayKey}-11")
        ->assertScript('location.pathname', parse_url(route('app.calendar', ['view' => 'week']), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('the status filter narrows the calendar to one kind of post', function () {
    [$user, $linkedin] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);

    $scheduled = calendarPagePost($linkedin, $day->copy()->setTime(10, 0));
    $draft = calendarPagePost($linkedin, $day->copy()->setTime(12, 0), PostStatus::Draft);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$scheduled->id}");
    $page->assertSeeIn('@calendar-status-filter', 'All posts')
        ->click('@calendar-status-filter');
    waitForCalendarTestId($page, 'calendar-status-drafts');
    $page->click('@calendar-status-drafts');
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-post-{$scheduled->id}\"]')");

    $page->assertMissing("@calendar-post-{$scheduled->id}")
        ->assertVisible("@calendar-post-{$draft->id}")
        ->assertSeeIn('@calendar-status-filter', 'Drafts')
        ->assertScript('new URLSearchParams(location.search).get("status")', 'drafts')
        ->assertNoJavaScriptErrors();
});

test('the no date panel lists undated drafts and a card opens the composer', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $undated = calendarPagePost($linkedin, null, PostStatus::Draft);
    $dated = calendarPagePost($x, now('UTC')->addDays(3), PostStatus::Draft);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month']));
    waitForCalendarTestId($page, 'calendar-no-date');
    $page->assertMissing('@calendar-undated-panel')
        ->click('@calendar-no-date');
    waitForCalendarTestId($page, "calendar-undated-{$undated->id}");

    $page->assertVisible('@calendar-undated-panel')
        ->assertSeeIn('@calendar-undated-panel', 'Undated drafts')
        ->assertMissing("@calendar-undated-{$dated->id}")
        ->assertAttribute('@calendar-no-date', 'aria-pressed', 'true')
        ->assertScript('new URLSearchParams(location.search).get("undated")', '1')
        ->click('@calendar-undated-close');
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-undated-panel\"]')");

    $page->assertMissing('@calendar-undated-panel')
        ->click('@calendar-no-date');
    waitForCalendarTestId($page, "calendar-undated-{$undated->id}");
    $page->click("@calendar-undated-{$undated->id}");
    waitForCalendarTestId($page, 'post-composer-dialog');

    $page->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('show posting times renders empty slots in the week and month views', function () {
    [$user, $linkedin] = calendarPageSetup();
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $weekday) {
        $schedule = $schedule->withTime($weekday, '15:00');
    }

    $linkedin->update(['posting_schedule' => $schedule]);

    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(2);
    $slotKey = "{$linkedin->id}-{$day->copy()->setTime(15, 0)->getTimestamp()}";

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    $page->script('window.localStorage.clear()');
    $page->refresh();
    waitForCalendarTestId($page, "calendar-posting-slot-{$slotKey}");

    expect($page->script("(() => { const chip = document.querySelector('[data-testid=\"calendar-posting-slot-{$slotKey}\"]'); return [chip.querySelector('svg[data-testid=\"calendar-posting-slot-icon-{$slotKey}\"]') !== null, chip.querySelector('img') === null]; })()"))
        ->toBe([true, true]);

    $page->hover("@calendar-posting-slot-{$slotKey}");
    $page->assertSeeIn("@calendar-posting-slot-{$slotKey}", __('posts.publish.add_post_in_slot'));

    expect($page->script("document.querySelector('[data-testid=\"calendar-posting-slot-{$slotKey}\"]').closest('[data-testid^=\"calendar-slot-\"]').dataset.testid"))
        ->toBe("calendar-slot-{$day->format('Y-m-d')}-15");

    $page->click('@calendar-menu');
    waitForCalendarTestId($page, 'calendar-toggle-slots');
    $page->click('@calendar-toggle-slots');
    waitForCalendarCondition($page, "!document.querySelector('[data-testid^=\"calendar-posting-slot-\"]')");

    $page->assertMissing("@calendar-posting-slot-{$slotKey}")
        ->assertScript('window.localStorage.getItem("publish.showSlots")', 'false');

    $page->refresh();
    waitForCalendarTestId($page, "calendar-slot-{$day->format('Y-m-d')}-15");
    $page->assertMissing("@calendar-posting-slot-{$slotKey}");

    $month = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d')]));
    waitForCalendarTestId($month, "calendar-posting-slot-{$slotKey}");

    expect($month->script("document.querySelector('[data-testid=\"calendar-posting-slot-{$slotKey}\"]').closest('[data-testid^=\"calendar-day-\"]').dataset.testid"))
        ->toBe("calendar-day-{$day->format('Y-m-d')}");

    $month->hover("@calendar-posting-slot-{$slotKey}");
    $month->assertSeeIn("@calendar-posting-slot-{$slotKey}", __('posts.publish.add_post_in_slot'))
        ->click("@calendar-posting-slot-{$slotKey}");
    waitForCalendarTestId($month, "composer-caption-{$linkedin->id}");
    $month->fill("@composer-caption-{$linkedin->id}", 'From a calendar slot');
    waitForCalendarTestId($month, 'composer-submit');

    expect($month->script('document.querySelector(\'[data-testid="composer-submit"]\').dataset.scheduleMode'))->toBe('custom');

    $month->click('@composer-submit');
    waitForCalendarCondition($month, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');
    $post = Post::query()->where('workspace_id', $linkedin->workspace_id)->sole();

    expect($post->scheduled_at->equalTo($day->copy()->setTime(15, 0)))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $month->assertNoJavaScriptErrors();
});

test('an imported post shows in the hour it was published', function () {
    [$user, $linkedin, $x] = calendarPageSetup();
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $day = $weekStart->copy()->addDays(1);
    $dayKey = $day->format('Y-m-d');

    $post = Post::factory()->imported()->create([
        'workspace_id' => $x->workspace_id,
        'published_at' => $day->copy()->setTime(16, 40),
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $x->id,
        'platform' => $x->platform,
        'status' => PostPlatformStatus::Published,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$post->id}");

    expect(calendarChipSlot($page, $post))->toBe("calendar-slot-{$dayKey}-16");
    $page->assertSeeIn("@calendar-post-{$post->id}", '16:40');
    $page->assertNoJavaScriptErrors();
});

test('clicking a scheduled post chip in the month view opens its timeline card in a popover', function () {
    [$user, $linkedin] = calendarPageSetup();
    $at = now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(10)->setTime(10, 0);
    $post = calendarPagePost($linkedin, $at);
    $post->update(['content' => '<p>Popover caption</p>']);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $at->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$post->id}");

    expect($page->script("(() => { const chip = document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]'); return [chip.tagName.toLowerCase(), chip.querySelector('svg') !== null, chip.querySelector('img') === null]; })()"))
        ->toBe(['button', true, true]);

    $page->click("@calendar-post-{$post->id}");
    waitForCalendarTestId($page, "calendar-post-popover-{$post->id}");

    $page->assertPresent("[data-testid=\"calendar-post-popover-{$post->id}\"] [data-testid=\"post-card-{$post->id}\"]")
        ->assertSeeIn("@calendar-post-popover-{$post->id}", 'Popover caption')
        ->assertPresent("@post-edit-{$post->id}")
        ->assertPresent("@post-publish-now-{$post->id}");

    $page->keys("@calendar-post-popover-{$post->id}", 'Escape');
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-post-popover-{$post->id}\"]')");

    $page->assertMissing("@calendar-post-popover-{$post->id}");
    expect($page->script('document.activeElement?.dataset.testid'))->toBe("calendar-post-{$post->id}");

    $page->click("@calendar-post-{$post->id}");
    waitForCalendarTestId($page, "calendar-post-overlay-{$post->id}");

    $page->assertVisible("@calendar-post-overlay-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-post-overlay-{$post->id}\"]').dispatchEvent(new PointerEvent('pointerdown', {bubbles: true, pointerType: 'mouse'}))");
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-post-popover-{$post->id}\"]')");

    $page->assertMissing("@calendar-post-popover-{$post->id}");

    $page->click("@calendar-post-{$post->id}");
    waitForCalendarTestId($page, "post-details-expand-{$post->id}");

    $page->script("document.querySelector('[data-testid=\"post-card-menu-{$post->id}\"]').dispatchEvent(new PointerEvent('pointermove', {bubbles: true, pointerType: 'mouse'}))");
    waitForCalendarTestId($page, "post-card-menu-tooltip-{$post->id}");
    $page->assertSeeIn("@post-card-menu-tooltip-{$post->id}", __('posts.publish.actions.more'));

    $page->click("@post-details-expand-{$post->id}");
    waitForCalendarTestId($page, "post-details-{$post->id}");

    $page->assertVisible("@post-details-{$post->id}")
        ->assertMissing("@calendar-post-popover-{$post->id}");

    $page->keys("@post-details-{$post->id}", 'Escape');
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"post-details-{$post->id}\"]') && !document.querySelector('[data-testid=\"calendar-post-popover-{$post->id}\"]')");

    expect($page->script("document.querySelector('[data-testid=\"calendar-post-popover-{$post->id}\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a published post popover shows its metrics, go to post and the sent menu', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    [$user] = calendarPageSetup();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $user->current_workspace_id, 'timezone' => 'UTC']);

    $post = Post::factory()->published()->create([
        'workspace_id' => $instagram->workspace_id,
        'user_id' => $user->id,
        'content' => 'Already live',
        'published_at' => now()->subHour(),
    ]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram,
        'platform_url' => 'https://www.instagram.com/p/abc/',
        'published_at' => now()->subHour(),
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $instagram->workspace_id,
        'social_account_id' => $instagram->id,
        'social_account_key' => $instagram->id,
        'post_platform_id' => $target->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'remote_id' => $target->platform_post_id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => [
            'reactions' => ['value' => 12, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month']));
    waitForCalendarTestId($page, "calendar-post-{$post->id}");
    $page->click("@calendar-post-{$post->id}");
    waitForCalendarTestId($page, "calendar-post-popover-{$post->id}");

    $page->assertPresent("[data-testid=\"calendar-post-popover-{$post->id}\"] [data-testid=\"post-metrics-{$post->id}\"]")
        ->assertPresent("@post-view-{$post->id}")
        ->assertMissing("@post-edit-{$post->id}");

    $page->script("document.querySelector('[data-testid=\"post-view-{$post->id}\"]').dispatchEvent(new PointerEvent('pointermove', {bubbles: true, pointerType: 'mouse'}))");
    waitForCalendarTestId($page, "post-view-tooltip-{$post->id}");
    $page->assertSeeIn("@post-view-tooltip-{$post->id}", __('posts.publish.actions.open_on_network', ['network' => 'Instagram']));

    $page->click("@post-card-menu-{$post->id}");
    waitForCalendarTestId($page, "post-card-menu-content-{$post->id}");

    expect($page->script("Array.from(document.querySelectorAll('[data-testid=\"post-card-menu-content-{$post->id}\"] [role=\"menuitem\"]')).map((element) => element.dataset.testid)"))
        ->toBe(["post-duplicate-{$post->id}", "post-details-open-{$post->id}"]);

    $page->click("@post-details-open-{$post->id}");
    waitForCalendarTestId($page, "post-details-{$post->id}");

    $page->assertVisible("@post-details-{$post->id}")
        ->assertMissing("@calendar-post-popover-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('a week view post card shows its network icon, time and plain text', function () {
    [$user, $linkedin] = calendarPageSetup();
    $weekStart = now('UTC')->startOfWeek()->addWeek();
    $post = calendarPagePost($linkedin, $weekStart->copy()->addDays(2)->setTime(11, 0));
    $post->update(['content' => '<p>Week text visible</p>']);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekStart->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$post->id}");

    $page->assertSeeIn("@calendar-post-text-{$post->id}", 'Week text visible')
        ->assertDontSeeIn("@calendar-post-{$post->id}", '<p>');

    expect($page->script("(() => { const chip = document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]'); return [chip.querySelector('svg') !== null, chip.querySelector('img') === null]; })()"))
        ->toBe([true, true]);

    $page->assertNoJavaScriptErrors();
});

test('an overflowing month day expands and collapses back', function () {
    [$user, $linkedin] = calendarPageSetup();
    $day = now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(12);
    $dayKey = $day->format('Y-m-d');
    $posts = collect(range(0, 3))->map(fn (int $index) => calendarPagePost($linkedin, $day->copy()->setTime(9 + $index, 0)));

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $dayKey]));
    waitForCalendarTestId($page, "calendar-more-{$dayKey}");

    $page->assertSeeIn("@calendar-more-{$dayKey}", __('calendar.more', ['count' => 1]))
        ->assertMissing("@calendar-post-{$posts[3]->id}")
        ->click("@calendar-more-{$dayKey}");
    waitForCalendarTestId($page, "calendar-post-{$posts[3]->id}");

    $page->assertVisible("@calendar-post-{$posts[3]->id}")
        ->assertSeeIn("@calendar-more-{$dayKey}", __('calendar.less'))
        ->click("@calendar-more-{$dayKey}");
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-post-{$posts[3]->id}\"]')");

    $page->assertMissing("@calendar-post-{$posts[3]->id}")
        ->assertSeeIn("@calendar-more-{$dayKey}", __('calendar.more', ['count' => 1]))
        ->assertNoJavaScriptErrors();
});

test('a popover action reloads the calendar and closes the popover', function () {
    [$user, $linkedin] = calendarPageSetup();
    $at = now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(10)->setTime(10, 0);
    $post = calendarPagePost($linkedin, $at);

    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $at->format('Y-m-d')]));
    waitForCalendarTestId($page, "calendar-post-{$post->id}");
    $page->click("@calendar-post-{$post->id}");
    waitForCalendarTestId($page, "post-card-menu-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitForCalendarTestId($page, "post-move-drafts-{$post->id}");
    $page->click("@post-move-drafts-{$post->id}");
    waitForCalendarCondition($page, "!document.querySelector('[data-testid=\"calendar-post-popover-{$post->id}\"]')");

    $page->assertMissing("@calendar-post-popover-{$post->id}")
        ->assertVisible("@calendar-post-{$post->id}")
        ->assertNoJavaScriptErrors();
    expect($post->refresh()->status)->toBe(PostStatus::Draft);
});
