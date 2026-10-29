<?php

declare(strict_types=1);

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Pest\Browser\Playwright\Client;

/**
 * @return array{0: User, 1: SocialAccount}
 */
function calendarDragSetup(string $timezone = 'UTC', ?string $slotTime = null): array
{
    $user = User::factory()->create(['timezone' => $timezone]);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $schedule = PostingSchedule::empty();

    if (filled($slotTime)) {
        foreach (range(0, 6) as $weekday) {
            $schedule = $schedule->withTime($weekday, $slotTime);
        }
    }

    return [
        $user,
        SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'timezone' => 'UTC',
            'posting_schedule' => $schedule,
        ]),
    ];
}

function calendarDragPost(SocialAccount $channel, ?CarbonImmutable $at, PostStatus $status = PostStatus::Scheduled, ?ScheduleMode $mode = ScheduleMode::Custom, array $attributes = []): Post
{
    return Post::factory()->forAccount($channel)->create(array_merge([
        'user_id' => $channel->workspace->user_id,
        'content' => 'A post to move',
        'status' => $status,
        'schedule_mode' => $status === PostStatus::Draft ? null : $mode,
        'scheduled_at' => $at,
    ], $attributes));
}

function calendarDragDay(): CarbonImmutable
{
    return now('UTC')->startOfWeek()->addWeek()->addDays(2)->startOfDay();
}

function calendarDragWeekUrl(CarbonImmutable $day, array $query = []): string
{
    return route('app.calendar', ['view' => 'week', 'week' => $day->format('Y-m-d'), ...$query]);
}

function calendarDragSlotKey(SocialAccount $channel, CarbonImmutable $at): string
{
    return "{$channel->id}-{$at->getTimestamp()}";
}

function calendarDragCellOf(mixed $page, Post $post): ?string
{
    return $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]')?.closest('[data-testid^=\"calendar-slot-\"], [data-testid^=\"calendar-day-\"]')?.dataset.testid ?? null");
}

/**
 * Drags a card onto a target with both ends on screen: down on the card once
 * it takes the pointer (a closing dialog may still hold it), one move onto
 * the target and up there.
 */
function calendarDragOnto(mixed $page, string $from, string $to): void
{
    calendarGesture($page, <<<JS
        query('{$from}').scrollIntoView({ block: 'center' });
        query('{$to}').scrollIntoView({ block: 'nearest' });
        const reachable = () => query('{$from}').contains(document.elementFromPoint(center('{$from}').x, center('{$from}').y));
        for (let i = 0; i < 100 && !reachable(); i++) {
            await pause(50);
        }
        await down(center('{$from}'));
        await move(center('{$to}'));
        await up(center('{$to}'));
    JS);
}

function calendarDragAssertLive(mixed $page, string $selector): void
{
    waitForState($page, $selector, "element.dataset.calendarDraggable === 'true'");
}

/**
 * Runs a gesture in the page. Pest drives the mouse only through whole
 * drags, so partial moves, holds and touch pointers are dispatched as
 * PointerEvents: `down`, `move`, `up` and `hover` (no button) take a point, `center` and `at`
 * give one, `pause` waits in the page and `state` reads the drag visuals.
 */
function calendarGesture(mixed $page, string $steps, string $pointerType = 'mouse'): mixed
{
    return $page->script(<<<JS
        (async () => {
            const pointerType = '{$pointerType}';
            const pointerId = pointerType === 'touch' ? 2 : 1;
            const query = (selector) => document.querySelector(selector.replace(/@([\w-]+)/g, '[data-testid="$1"]'));
            const center = (selector) => {
                const box = query(selector).getBoundingClientRect();

                return { x: box.left + box.width / 2, y: box.top + box.height / 2 };
            };
            const at = (point, dx = 0, dy = 0) => ({ x: point.x + dx, y: point.y + dy });
            const fire = (type, point, buttons = type === 'pointerup' ? 0 : 1) => (document.elementFromPoint(point.x, point.y) ?? document.body).dispatchEvent(new PointerEvent(type, {
                bubbles: true,
                cancelable: true,
                composed: true,
                pointerId,
                pointerType,
                isPrimary: true,
                button: type === 'pointermove' ? -1 : 0,
                buttons,
                clientX: point.x,
                clientY: point.y,
            }));
            const frame = () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const down = async (point) => { fire('pointerdown', point); await frame(); };
            const move = async (point) => { fire('pointermove', point); await frame(); };
            const up = async (point) => { fire('pointerup', point); await frame(); };
            const hover = async (point) => { fire('pointermove', point, 0); await frame(); };
            const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
            const touchMovePrevented = (selector) => {
                const element = query(selector);
                const box = element.getBoundingClientRect();
                const touch = new Touch({ identifier: pointerId, target: element, clientX: box.left + 2, clientY: box.top + 2 });
                const event = new TouchEvent('touchmove', { bubbles: true, cancelable: true, touches: [touch], targetTouches: [touch], changedTouches: [touch] });
                element.dispatchEvent(event);

                return event.defaultPrevented;
            };
            const state = () => {
                const preview = query('@calendar-drag-preview');

                return {
                    dragging: preview !== null,
                    opacity: preview ? getComputedStyle(preview).opacity : null,
                    rotated: preview ? getComputedStyle(preview).rotate !== 'none' || getComputedStyle(preview).transform !== 'none' : false,
                };
            };
            {$steps}
        })()
    JS);
}

test('dragging a custom post onto an hour moves it to that hour', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, "@calendar-slot-{$key}-12 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->status)->toBe(PostStatus::Scheduled);
    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertNoJavaScriptErrors();
});

test('dragging onto a slot of its channel takes the slot\'s exact time in the queue', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '15:25');
    $day = calendarDragDay();
    $slotAt = $day->setTime(15, 25);
    $post = calendarDragPost($channel, $day->setTime(13, 30));
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-slot-{$day->format('Y-m-d')}-15 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('a custom post dropped on a free slot of its channel goes back into the queue', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '15:25');
    $day = calendarDragDay();
    $slotAt = $day->setTime(15, 25);
    $post = calendarDragPost($channel, $day->setTime(13, 30));
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    waitForState($page, "@post-schedule-mode-{$post->id}", "element.dataset.mode === 'custom'");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitForState($page, "@calendar-slot-{$day->format('Y-m-d')}-15 @post-schedule-mode-{$post->id}", "element.dataset.mode === 'queue'");

    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved', ['time' => calendarDragWhen($slotAt)]))
        ->assertDontSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.unqueued_suffix'));
    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('dragging a queued post off its slot asks first; cancel leaves it', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $queuedAt = $day->setTime(9, 25);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-dialog');
    waitForState($page, '@calendar-custom-time-cancel', 'document.activeElement === element');

    expect($page->script('document.activeElement?.dataset.testid'))->toBe('calendar-custom-time-cancel');
    $page->assertSeeIn('@calendar-custom-time-dialog', __('calendar.reschedule.custom_time.title'))
        ->assertSeeIn('@calendar-custom-time-description', "{$queuedAt->format('M j, g:i A')} (UTC)")
        ->assertSeeIn('@calendar-custom-time-description', "{$day->setTime(11, 0)->format('M j, g:i A')} (UTC)")
        ->assertDontSeeIn('@calendar-custom-time-description', 'posting times');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $styles = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-testid="calendar-custom-time-dialog"]');
            const title = dialog.querySelector('[data-slot="alert-dialog-title"]');
            const strong = dialog.querySelector('[data-testid="calendar-custom-time-description"] strong');
            const cancel = dialog.querySelector('[data-testid="calendar-custom-time-cancel"]');
            const confirm = dialog.querySelector('[data-testid="calendar-custom-time-confirm"]');
            const box = getComputedStyle(dialog);

            return {
                width: dialog.offsetWidth,
                padding: box.padding,
                gap: box.rowGap,
                radius: box.borderTopLeftRadius,
                title: [getComputedStyle(title).fontSize, getComputedStyle(title).lineHeight, getComputedStyle(title).fontWeight],
                strong: [getComputedStyle(strong).fontWeight, getComputedStyle(strong.querySelector('i')).fontStyle],
                buttons: [cancel.offsetHeight, confirm.offsetHeight],
                cancelFirst: cancel.offsetLeft + cancel.offsetWidth <= confirm.offsetLeft,
                footer: dialog.querySelector('[data-testid="calendar-custom-time-footer"]').offsetHeight,
            };
        })()
    JS);

    expect($styles)->toBe([
        'width' => 480,
        'padding' => '24px 0px 16px',
        'gap' => '16px',
        'radius' => '16px',
        'title' => ['16px', '20px', '550'],
        'strong' => ['650', 'italic'],
        'buttons' => [32, 32],
        'cancelFirst' => true,
        'footer' => 32,
    ]);

    $page->click('@calendar-custom-time-cancel');
    waitForGone($page, '@calendar-custom-time-dialog');

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-09");
    $post->refresh();
    expect($post->scheduled_at->equalTo($queuedAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('confirming makes the queued post custom', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-confirm');
    $page->click('@calendar-custom-time-confirm');
    waitFor($page, "@calendar-slot-{$key}-11 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertNoJavaScriptErrors();
});

test('ticking the box and confirming stores the choice and the next drop skips the dialog', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $nextKey = $day->addDay()->format('Y-m-d');
    $first = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $second = calendarDragPost($channel, $day->addDay()->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$first->id}");
    calendarDragOnto($page, "@calendar-post-{$first->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-skip');
    $page->click('@calendar-custom-time-skip');
    $page->click('@calendar-custom-time-confirm');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($page->script("localStorage.getItem('calendar.skipCustomTimeConfirm')"))->toBe('true');
    waitFor($page, '@calendar-posting-slot-'.calendarDragSlotKey($channel, $day->setTime(9, 25)));

    waitFor($page, "@calendar-post-{$second->id}");
    calendarDragOnto($page, "@calendar-post-{$second->id}", "@calendar-slot-{$nextKey}-11");
    waitFor($page, "@calendar-slot-{$nextKey}-11 @calendar-post-{$second->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitFor($page, '@calendar-posting-slot-'.calendarDragSlotKey($channel, $day->addDay()->setTime(9, 25)));

    $page->assertMissing('@calendar-custom-time-dialog');
    $second->refresh();
    expect($second->scheduled_at->equalTo($day->addDay()->setTime(11, 0)))->toBeTrue()
        ->and($second->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertNoJavaScriptErrors();
});

test('ticking the box and cancelling stores nothing', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-skip');
    $page->click('@calendar-custom-time-skip');
    $page->click('@calendar-custom-time-cancel');
    waitForGone($page, '@calendar-custom-time-dialog');

    expect($page->script("localStorage.getItem('calendar.skipCustomTimeConfirm')"))->toBeNull()
        ->and($post->refresh()->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('dropping a queued post on a free slot of its channel moves it there in the queue without asking', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $slotAt = $day->addDay()->setTime(9, 25);
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-slot-{$slotAt->format('Y-m-d')}-09 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    waitFor($page, '@calendar-reschedule-success-toast');

    $toast = $page->script('document.querySelector(\'[data-testid="calendar-reschedule-success-toast"]\')?.textContent ?? ""');

    expect($toast)->toContain('Rescheduled your post')
        ->not->toContain(__('calendar.reschedule.toast.unqueued_suffix'));
    $page->assertMissing('@calendar-custom-time-dialog');
    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('past hours and past days are not drop targets', function () {
    [$user, $channel] = calendarDragSetup();
    $now = now('UTC');
    $yesterday = $now->subDay()->startOfDay();
    $today = $now->format('Y-m-d');
    $at = $now->addDay()->startOfHour();
    $post = calendarDragPost($channel, $at);
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $yesterday->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$today}-{$now->format('H')}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$yesterday->format('Y-m-d')}-{$now->format('H')}");
    waitForGone($page, '@calendar-grid[aria-busy]');
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$at->format('Y-m-d')}-{$at->format('H')}");

    $month = visit(route('app.calendar', ['view' => 'month', 'month' => $yesterday->format('Y-m-d'), 'undated' => 1]));
    waitFor($month, "@calendar-undated-{$draft->id}");
    calendarDragAssertLive($month, "@calendar-undated-{$draft->id}");
    calendarDragOnto($month, "@calendar-undated-{$draft->id}", "@calendar-day-{$yesterday->format('Y-m-d')}");
    waitForGone($month, '@calendar-grid[aria-busy]');
    $month->assertVisible("@calendar-undated-{$draft->id}");

    expect($post->refresh()->scheduled_at->equalTo($at))->toBeTrue()
        ->and($draft->refresh()->scheduled_at)->toBeNull();
    $page->assertMissing('@calendar-reschedule-error-toast');

    $validAt = $at->hour > 0 ? $at->subHour() : $at->addHour();
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$validAt->format('Y-m-d')}-{$validAt->format('H')}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($validAt))->toBeTrue();
    $page->assertNoJavaScriptErrors();
    $month->assertNoJavaScriptErrors();
});

test('month drop keeps the clock time', function () {
    [$user, $channel] = calendarDragSetup();
    $from = now('UTC')->startOfMonth()->addMonth()->addDays(3)->setTime(14, 35);
    $to = $from->addDays(2);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $from->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$to->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$to->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($to))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('month drop on today after that time lands five minutes from now', function () {
    [$user, $channel] = calendarDragSetup();
    $today = now('UTC')->startOfDay();
    $gridEnd = $today->endOfMonth()->endOfWeek($user->week_starts_on->lastDay())->startOfDay();
    $post = $today->equalTo($gridEnd)
        ? calendarDragPost($channel, $today->subDay(), PostStatus::Draft)
        : calendarDragPost($channel, $today->addDay());
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $today->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$today->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$today->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $scheduledAt = $post->refresh()->scheduled_at;
    expect($scheduledAt->between(now()->addMinutes(3), now()->addMinutes(6)))->toBeTrue()
        ->and($scheduledAt->second)->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('drafts move and stay drafts; the No Date panel takes and gives them', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-slot-{$key}-11");
    waitFor($page, "@calendar-slot-{$key}-11 @calendar-post-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitForGone($page, "@calendar-undated-{$draft->id}");

    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);

    calendarDragOnto($page, "@calendar-post-{$draft->id}", "@calendar-slot-{$key}-13");
    waitFor($page, "@calendar-slot-{$key}-13 @calendar-post-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(13, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);

    calendarDragOnto($page, "@calendar-post-{$draft->id}", '@calendar-undated-panel');
    waitFor($page, "@calendar-undated-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitForGone($page, "@calendar-post-{$draft->id}");

    $draft->refresh();
    expect($draft->scheduled_at)->toBeNull()
        ->and($draft->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('a draft from the No Date panel dropped on a month day lands at nine', function () {
    [$user, $channel] = calendarDragSetup();
    $day = now('UTC')->startOfMonth()->addMonth()->addDays(4)->startOfDay();
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d'), 'undated' => 1]));
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-day-{$day->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$day->format('Y-m-d')} @calendar-post-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(9, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('a scheduled post is not taken by the No Date panel', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-post-{$post->id}");
    waitFor($page, '@calendar-undated-empty');
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $hovered = calendarGesture($page, <<<JS
        query('@calendar-post-{$post->id}').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-undated-panel'));
        const highlighted = query('@calendar-undated-panel').classList.contains('border-primary');
        await up(center('@calendar-undated-panel'));

        return { highlighted, dragging: state().dragging, after: query('@calendar-undated-panel').classList.contains('border-primary') };
    JS);

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($hovered)->toBe(['highlighted' => true, 'dragging' => false, 'after' => false]);

    calendarDragOnto($page, "@calendar-post-{$post->id}", '@calendar-undated-panel');

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$day->format('Y-m-d')}-10");
    $page->assertMissing("@calendar-undated-{$post->id}");

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$day->format('Y-m-d')}-11");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('published posts are not draggable', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $published = calendarDragPost($channel, $day->setTime(8, 0), PostStatus::Published, attributes: ['published_at' => $day->setTime(8, 0)]);
    $failed = calendarDragPost($channel, $day->setTime(9, 0), PostStatus::Failed);
    $pending = calendarDragPost($channel, $day->setTime(10, 0), PostStatus::PendingApproval);
    $movable = calendarDragPost($channel, $day->setTime(12, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$movable->id}");
    waitForState($page, "@calendar-post-{$movable->id}", "element.dataset.calendarDraggable === 'true'");

    $draggable = fn (Post $post): ?string => $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]').dataset.calendarDraggable ?? null");

    expect($draggable($movable))->toBe('true')
        ->and($draggable($published))->toBeNull()
        ->and($draggable($failed))->toBeNull()
        ->and($draggable($pending))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('a recurring post can be dragged and stays recurring', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(9, 0), attributes: [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 3,
    ]);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    waitFor($page, "@calendar-post-recurring-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$day->format('Y-m-d')}-10");
    waitFor($page, "@calendar-slot-{$day->format('Y-m-d')}-10 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();

    expect($post->scheduled_at->equalTo($day->setTime(10, 0)))->toBeTrue()
        ->and($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_interval)->toBe(1)
        ->and($post->recurrence_remaining)->toBe(3);
    waitFor($page, "@calendar-post-recurring-{$post->id}");
    $page->assertNoJavaScriptErrors();
});

test('the drop uses the display zone', function () {
    [$user, $channel] = calendarDragSetup();
    $tokyoDay = calendarDragDay()->shiftTimezone('Asia/Tokyo');
    $key = $tokyoDay->format('Y-m-d');
    $post = calendarDragPost($channel, $tokyoDay->setTime(8, 30)->utc());
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($tokyoDay, ['tz' => 'Asia/Tokyo']));
    waitFor($page, "@calendar-slot-{$key}-08 @calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-10");
    waitFor($page, "@calendar-slot-{$key}-10 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($tokyoDay->setTime(10, 0)))->toBeTrue()
        ->and($post->scheduled_at->utc()->format('H:i'))->toBe('01:00');
    $page->assertNoJavaScriptErrors();
});

test('a post published meanwhile goes back and the reason is shown', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $post->forceFill(['status' => PostStatus::Published, 'published_at' => $at])->save();
    calendarDragWatchAnnouncer($page);

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@flash-banner-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@flash-banner-toast', __('posts.flash.cannot_edit_finalized'));
    expect($page->script('document.querySelectorAll(\'[data-testid="calendar-reschedule-success-toast"]\').length'))->toBe(0)
        ->and(array_slice(calendarDragAnnouncements($page), -1))->toBe([__('calendar.reschedule.announce.returned')])
        ->and(collect(calendarDragAnnouncements($page))->filter(fn (string $text): bool => $text === __('calendar.reschedule.announce.returned'))->count())->toBe(1);
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a time that passed meanwhile is refused with the reason', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $this->travelTo($day->addDays(2));

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-reschedule-error-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('calendar.reschedule.errors.past'));
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('two drops in a row both land', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $first = calendarDragPost($channel, $day->setTime(10, 0));
    $second = calendarDragPost($channel, $day->setTime(11, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$first->id}");
    calendarDragAssertLive($page, "@calendar-post-{$second->id}");
    calendarDragWatchAnnouncer($page);

    calendarGesture($page, <<<JS
        query('@calendar-slot-{$key}-11').scrollIntoView({ block: 'center' });
        const first = center('@calendar-post-{$first->id}');
        const second = center('@calendar-post-{$second->id}');
        const target = center('@calendar-slot-{$key}-12');
        fire('pointerdown', first);
        fire('pointermove', target);
        fire('pointerup', target);
        fire('pointerdown', second);
        fire('pointermove', target);
        fire('pointerup', target);
    JS);
    waitFor($page, "@calendar-slot-{$key}-12 @calendar-post-{$first->id}");
    waitFor($page, "@calendar-slot-{$key}-12 @calendar-post-{$second->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($first->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue()
        ->and($second->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue()
        ->and(calendarDragCellOf($page, $first))->toBe("calendar-slot-{$key}-12")
        ->and(calendarDragCellOf($page, $second))->toBe("calendar-slot-{$key}-12")
        ->and(calendarDragAnnouncements($page))->not->toContain(__('calendar.reschedule.announce.returned'));
    $page->assertMissing('@calendar-reschedule-error-toast')
        ->assertNoJavaScriptErrors();
});

test('a move by a member who needs approval says it was sent for approval', function () {
    [$owner, $channel] = calendarDragSetup();
    $member = User::factory()->create(['account_id' => $owner->account_id, 'timezone' => 'UTC']);
    $channel->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $channel->workspace_id]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 0), attributes: ['user_id' => $member->id]);
    $this->actingAs($member);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-reschedule-approval-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $announcements = calendarDragAnnouncements($page);
    expect(array_slice($announcements, -1))->toBe([__('calendar.reschedule.announce.moved', ['from' => calendarDragWhen($day->setTime(10, 0)), 'to' => calendarDragWhen($day->setTime(11, 0))])])
        ->and($announcements)->not->toContain(__('calendar.reschedule.announce.returned'));

    $page->assertSeeIn('@calendar-reschedule-approval-toast', __('posts.approvals.requested'));
    expect($page->script('document.querySelectorAll(\'[data-testid="calendar-reschedule-success-toast"]\').length'))->toBe(0);
    expect($post->refresh()->status)->toBe(PostStatus::PendingApproval);
    $page->assertNoJavaScriptErrors();
});

test('a drag that ends on its own cell sends nothing', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 40);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-10");

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10");

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the custom time dialog footer fits a phone', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$day->format('Y-m-d')}-11");
    waitFor($page, '@calendar-custom-time-dialog');
    $page->resize(390, 844);
    waitForState($page, '@calendar-custom-time-dialog', 'element.offsetWidth === 390');

    $layout = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-testid="calendar-custom-time-dialog"]');
            const footer = dialog.querySelector('[data-testid="calendar-custom-time-footer"]');
            const bounds = dialog.getBoundingClientRect();

            return {
                inside: [...footer.children].every((child) => {
                    const box = child.getBoundingClientRect();

                    return box.left >= bounds.left && box.right <= bounds.right;
                }),
                atBottom: Math.round(bounds.bottom - footer.getBoundingClientRect().bottom) <= 16,
            };
        })()
    JS);

    expect($layout)->toBe(['inside' => true, 'atBottom' => true]);
    $page->assertNoJavaScriptErrors();
});

test('a mouse drag starts only after 4px', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        const start = center('@calendar-post-{$post->id}');
        await down(start);
        await move(at(start, 3, 0));
        const short = state();
        await move(at(start, 5, 0));

        return [short, state()];
    JS);

    expect($steps[0]['dragging'])->toBeFalse()
        ->and($steps[1]['dragging'])->toBeTrue();

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertMissing('@calendar-drag-preview')
        ->assertNoJavaScriptErrors();
});

test('a touch press held 250ms drags the card', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day))->on()->iPadPro()->resize(1024, 1366);
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-slot-{$key}-12\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        const start = center('@calendar-post-{$post->id}');
        await down(start);
        await move(at(start, 2, 2));
        await pause(100);
        const early = state();
        await pause(250);
        const held = state();
        await move(center('@calendar-slot-{$key}-12'));
        const prevented = touchMovePrevented('@calendar-post-{$post->id}');
        const touchAction = getComputedStyle(query('@calendar-post-{$post->id}')).touchAction;
        await up(center('@calendar-slot-{$key}-12'));

        return { early: early.dragging, held: held.dragging, prevented, touchAction };
    JS, 'touch');

    expect($steps)->toBe(['early' => false, 'held' => true, 'prevented' => true, 'touchAction' => 'none']);

    waitFor($page, "@calendar-slot-{$key}-12 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a touch swipe before 250ms scrolls and does not drag', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day))->on()->iPadPro()->resize(1024, 1366);
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        const start = center('@calendar-post-{$post->id}');
        const touchAction = getComputedStyle(query('@calendar-post-{$post->id}')).touchAction;
        await down(start);
        await pause(50);
        await move(at(start, 0, -20));
        const prevented = touchMovePrevented('@calendar-post-{$post->id}');
        await pause(300);
        const after = state();
        await move(center('@calendar-slot-{$key}-12'));
        const prevented2 = touchMovePrevented('@calendar-post-{$post->id}');
        await up(center('@calendar-slot-{$key}-12'));

        return { touchAction, prevented: prevented || prevented2, dragging: after.dragging };
    JS, 'touch');

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($steps)->toBe(['touchAction' => 'auto', 'prevented' => false, 'dragging' => false])
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a tap still opens the post', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day))->on()->iPadPro()->resize(1024, 1366);
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->page()->locator("[data-testid=\"calendar-post-{$post->id}\"]")->tap();
    waitFor($page, "@calendar-post-popover-{$post->id}");

    $page->assertVisible("@calendar-post-popover-{$post->id}")
        ->assertMissing('@calendar-drag-preview');
    $page->keys("@calendar-post-popover-{$post->id}", 'Escape');
    waitForGone($page, "@calendar-post-overlay-{$post->id}");

    $page->click("@calendar-post-{$post->id}");
    waitFor($page, "@calendar-post-popover-{$post->id}");
    $page->assertVisible("@calendar-post-popover-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('mid-drag the preview, placeholder, hovered cell and hovered slot show the drag', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '15:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(13, 30));
    $other = calendarDragPost($channel, $day->setTime(12, 10));
    $slotKey = calendarDragSlotKey($channel, $day->setTime(15, 25));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-slot-{$key}-13\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        const origin = () => query('@calendar-post-{$post->id}');
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const overCell = {
            ...state(),
            placeholder: origin().classList.contains('bg-muted') && getComputedStyle(origin().firstElementChild).visibility === 'hidden',
            cell: query('@calendar-slot-{$key}-12').classList.contains('bg-primary/15'),
            dimmed: getComputedStyle(query('@calendar-post-{$other->id}')).opacity,
        };
        await move(center('@calendar-posting-slot-{$slotKey}'));
        const overSlot = {
            slot: query('@calendar-posting-slot-{$slotKey}').classList.contains('bg-accent'),
            cell: query('@calendar-slot-{$key}-15').classList.contains('bg-primary/15'),
            previous: query('@calendar-slot-{$key}-12').classList.contains('bg-primary/15'),
        };

        return { overCell, overSlot };
    JS);

    expect($steps['overCell'])->toBe([
        'dragging' => true,
        'opacity' => '0.8',
        'rotated' => true,
        'placeholder' => true,
        'cell' => true,
        'dimmed' => '0.5',
    ])->and($steps['overSlot'])->toBe(['slot' => true, 'cell' => false, 'previous' => false]);

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertNoJavaScriptErrors();
});

test('the grid auto-scrolls near its bottom edge', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(1, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-time-grid\"]').scrollTop = 0");

    $scrolled = calendarGesture($page, <<<JS
        const grid = query('@calendar-time-grid');
        const box = grid.getBoundingClientRect();
        const start = center('@calendar-post-{$post->id}');
        await down(start);
        await move({ x: start.x, y: box.bottom - 10 });
        for (let i = 0; i < 60 && grid.scrollTop === 0; i++) {
            await pause(50);
        }

        return grid.scrollTop;
    JS);

    expect($scrolled)->toBeGreaterThan(0);

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertNoJavaScriptErrors();
});

test('Esc cancels a drag', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-slot-{$key}-12\"]').scrollIntoView({ block: 'center' })");

    $over = calendarGesture($page, <<<JS
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));

        return state().dragging && query('@calendar-slot-{$key}-12').classList.contains('bg-primary/15');
    JS);

    expect($over)->toBeTrue();

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');

    $after = calendarGesture($page, <<<JS
        await up(center('@calendar-slot-{$key}-12'));

        return query('@calendar-slot-{$key}-12').classList.contains('bg-primary/15');
    JS);

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($after)->toBeFalse()
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a drag ends when the window loses focus or the mouse button is already up', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-slot-{$key}-12\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const dragging = state().dragging;
        window.dispatchEvent(new Event('blur'));
        await frame();
        const blurred = state().dragging;
        await move(center('@calendar-slot-{$key}-13'));
        await up(center('@calendar-slot-{$key}-13'));

        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const again = state().dragging;
        await hover(center('@calendar-slot-{$key}-13'));
        const buttonless = state().dragging;
        await up(center('@calendar-slot-{$key}-13'));

        return { dragging, blurred, again, buttonless, cell: query('@calendar-slot-{$key}-12').classList.contains('bg-primary/15') };
    JS);

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($steps)->toBe(['dragging' => true, 'blurred' => false, 'again' => true, 'buttonless' => false, 'cell' => false])
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

function calendarDragWatchAnnouncer(mixed $page): void
{
    $page->script(<<<'JS'
        window.__announcements = [];
        new MutationObserver(() => {
            const text = document.querySelector('[data-testid="calendar-drag-announcer"]').textContent.trim();

            if (text !== '') {
                window.__announcements.push(text);
            }
        }).observe(document.querySelector('[data-testid="calendar-drag-announcer"]'), { childList: true, characterData: true, subtree: true });
    JS);
}

/**
 * @return list<string>
 */
function calendarDragAnnouncements(mixed $page): array
{
    return $page->script('window.__announcements');
}

function calendarDragWhen(CarbonImmutable $at, bool $dateOnly = false): string
{
    $date = $at->format($at->year === now('UTC')->year ? 'l, F j' : 'l, F j, Y');

    return $dateOnly
        ? $date
        : __('calendar.reschedule.announce.date_time', ['date' => $date, 'time' => $at->format('g:i A')]);
}

test('a drop in the week view announces the target once and then the move once', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $from = $day->setTime(10, 30);
    $to = $day->setTime(12, 0);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, "@calendar-slot-{$key}-12 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragAnnouncements($page))->toBe([
        __('calendar.reschedule.announce.over', ['from' => calendarDragWhen($from), 'target' => calendarDragWhen($to)]),
        __('calendar.reschedule.announce.moved', ['from' => calendarDragWhen($from), 'to' => calendarDragWhen($to)]),
    ]);
    $page->assertNoJavaScriptErrors();
});

test('a month drop announces the date only for the target', function () {
    [$user, $channel] = calendarDragSetup();
    $from = now('UTC')->startOfMonth()->addMonth()->addDays(3)->setTime(14, 35);
    $to = $from->addDays(2);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $from->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$to->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$to->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragAnnouncements($page))->toBe([
        __('calendar.reschedule.announce.over', ['from' => calendarDragWhen($from), 'target' => calendarDragWhen($to, dateOnly: true)]),
        __('calendar.reschedule.announce.moved', ['from' => calendarDragWhen($from), 'to' => calendarDragWhen($to, dateOnly: true)]),
    ]);
    $page->assertNoJavaScriptErrors();
});

test('a drag released outside every target returns the card and announces it once', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $from = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);

    calendarGesture($page, <<<JS
        query('@calendar-slot-{$key}-12').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        await move({ x: 2, y: 2 });
        await up({ x: 2, y: 2 });
    JS);
    waitForGone($page, '@calendar-drag-preview');

    expect(calendarDragAnnouncements($page))->toBe([
        __('calendar.reschedule.announce.over', ['from' => calendarDragWhen($from), 'target' => calendarDragWhen($day->setTime(12, 0))]),
        __('calendar.reschedule.announce.returned'),
    ])
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($from))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('Esc announces that the card went back, once', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);

    calendarGesture($page, <<<JS
        query('@calendar-slot-{$key}-12').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
    JS);
    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    calendarGesture($page, "await up(center('@calendar-slot-{$key}-12'));");

    $announcements = calendarDragAnnouncements($page);
    expect($announcements)->toHaveCount(2)
        ->and($announcements[1])->toBe(__('calendar.reschedule.announce.returned'));
    $page->assertNoJavaScriptErrors();
});

test('the No Date panel highlights for a scheduled post, refuses it and announces the return', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $from = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-post-{$post->id}");
    waitFor($page, '@calendar-undated-empty');
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);

    $highlighted = calendarGesture($page, <<<JS
        query('@calendar-post-{$post->id}').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-undated-panel'));
        const highlighted = query('@calendar-undated-panel').classList.contains('border-primary');
        await up(center('@calendar-undated-panel'));

        return highlighted;
    JS);
    waitForGone($page, '@calendar-drag-preview');

    expect($highlighted)->toBeTrue()
        ->and(calendarDragAnnouncements($page))->toBe([__('calendar.reschedule.announce.returned')])
        ->and($post->refresh()->scheduled_at->equalTo($from))->toBeTrue();
    expect($page->script('document.querySelectorAll(\'[data-testid="calendar-reschedule-success-toast"]\').length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('dropping a dated draft on No Date announces it and toasts', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $from = $day->setTime(10, 0);
    $draft = calendarDragPost($channel, $from, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-post-{$draft->id}");
    waitFor($page, '@calendar-undated-empty');
    calendarDragAssertLive($page, "@calendar-post-{$draft->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$draft->id}", '@calendar-undated-panel');
    waitFor($page, "@calendar-undated-{$draft->id}");
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragAnnouncements($page))->toBe([
        __('calendar.reschedule.announce.over', ['from' => calendarDragWhen($from), 'target' => __('calendar.no_date')]),
        __('calendar.reschedule.announce.undated', ['from' => calendarDragWhen($from)]),
    ]);
    expect($page->script(<<<'JS'
        (() => {
            const toast = document.querySelector('[data-testid="calendar-reschedule-success-toast"]').getBoundingClientRect();
            const centre = toast.left + toast.width / 2;

            return {
                top: toast.top < window.innerHeight / 4,
                centred: Math.abs(centre - window.innerWidth / 2) <= 2,
            };
        })()
    JS))->toBe(['top' => true, 'centred' => true]);
    expect($page->script(<<<'JS'
        (() => {
            const toast = document.querySelector('[data-testid="calendar-reschedule-success-toast"]');
            const style = getComputedStyle(toast);
            const icon = toast.querySelector('[data-icon]').getBoundingClientRect();
            const content = toast.querySelector('[data-content]').getBoundingClientRect();
            const close = toast.querySelector('[data-close-button]').getBoundingClientRect();

            return {
                width: toast.offsetWidth,
                padding: style.padding,
                radius: style.borderTopLeftRadius,
                font: [style.fontSize, style.lineHeight, style.fontWeight],
                icon: [icon.width, Math.round(icon.top - toast.getBoundingClientRect().top)],
                closeAfterContent: close.left >= content.right,
                closeSize: close.width,
            };
        })()
    JS))->toBe([
        'width' => 480,
        'padding' => '12px 16px 12px 12px',
        'radius' => '12px',
        'font' => ['14px', '21px', '400'],
        'icon' => [24, 12],
        'closeAfterContent' => true,
        'closeSize' => 24,
    ]);
    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.undated'))
        ->assertNoJavaScriptErrors();
});

test('a draft from the No Date panel announces where it is over, then its channel and new time, and toasts as a draft', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $to = $day->setTime(11, 0);
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragAssertLive($page, "@calendar-undated-{$draft->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-slot-{$key}-11");
    waitFor($page, "@calendar-slot-{$key}-11 @calendar-post-{$draft->id}");
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragAnnouncements($page))->toBe([
        __('calendar.reschedule.announce.over_undated', ['channel' => $channel->display_label, 'target' => calendarDragWhen($to)]),
        __('calendar.reschedule.announce.from_undated', ['channel' => $channel->display_label, 'to' => calendarDragWhen($to)]),
    ]);
    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved_draft', ['time' => calendarDragWhen($to)]))
        ->assertNoJavaScriptErrors();
});

test('every draggable card describes how to drag it', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(10, 0));
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    waitFor($page, "@calendar-post-{$post->id}");
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-undated-{$draft->id}");

    $describe = fn (string $selector): ?string => $page->script("(() => { const id = document.querySelector('[data-testid=\"{$selector}\"]').getAttribute('aria-describedby'); return id ? document.getElementById(id)?.textContent.trim() ?? null : null; })()");

    $linkText = $page->script("document.querySelector('[data-testid=\"calendar-undated-{$draft->id}\"]').textContent");
    expect($linkText)->not->toContain(__('calendar.reschedule.drag_hint_undated', ['channel' => $channel->display_label]));

    expect($describe("calendar-post-{$post->id}"))->toBe(__('calendar.reschedule.drag_hint'))
        ->and($describe("calendar-undated-{$draft->id}"))->toBe(__('calendar.reschedule.drag_hint_undated', ['channel' => $channel->display_label]));
    $page->assertNoJavaScriptErrors();
});

test('a moved post toasts its new time', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, '@calendar-reschedule-success-toast');

    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved', ['time' => calendarDragWhen($day->setTime(12, 0))]))
        ->assertNoJavaScriptErrors();
});

test('a month move toasts the date only', function () {
    [$user, $channel] = calendarDragSetup();
    $from = now('UTC')->startOfMonth()->addMonth()->addDays(3)->setTime(14, 35);
    $to = $from->addDays(2);
    $post = calendarDragPost($channel, $from);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $from->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$to->format('Y-m-d')}");
    waitFor($page, '@calendar-reschedule-success-toast');

    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved', ['time' => calendarDragWhen($to, dateOnly: true)]))
        ->assertNoJavaScriptErrors();
});

test('a queued post that leaves the queue says its slot is free again', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, '@calendar-custom-time-dialog');
    $page->click('@calendar-custom-time-confirm');
    waitFor($page, '@calendar-reschedule-success-toast');

    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved', ['time' => calendarDragWhen($day->setTime(12, 0))]).' '.__('calendar.reschedule.toast.unqueued_suffix'))
        ->assertNoJavaScriptErrors();
});

test('toasts and announcements write dates in the order of the user\'s language', function () {
    [$user, $channel] = calendarDragSetup();
    $user->update(['locale' => Locale::PortugueseBrazil]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $to = $day->setTime(12, 0);
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $expected = $page->script("new Intl.DateTimeFormat('pt-BR', { weekday: 'long', month: 'long', day: 'numeric', timeZone: 'UTC' }).format(new Date('{$to->format('Y-m-d\\TH:i:s')}Z'))");
    $toast = $page->script('document.querySelector(\'[data-testid="calendar-reschedule-success-toast"]\').textContent');
    $announced = implode(' ', calendarDragAnnouncements($page));

    expect($toast)->toContain($expected)
        ->and($announced)->toContain($expected);
    $page->assertNoJavaScriptErrors();
});

test('toasts and announcements follow the user\'s 24-hour clock', function () {
    [$user, $channel] = calendarDragSetup();
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-15");
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $toast = $page->script('document.querySelector(\'[data-testid="calendar-reschedule-success-toast"]\').textContent');
    $announced = implode(' ', calendarDragAnnouncements($page));

    expect($toast)->toContain('15:00')
        ->not->toContain('3:00 PM')
        ->and($announced)->toContain('15:00')
        ->and($announced)->toContain('10:30')
        ->and($announced)->not->toContain('PM');
    $page->assertNoJavaScriptErrors();
});

/**
 * Sends a Playwright protocol call to the page or its context, for what Pest
 * does not expose: the real mouse and the clock.
 */
function calendarDragPlaywright(object $target, string $method, array $params = []): void
{
    $guid = (fn (): string => $this->guid)->call($target);

    iterator_to_array(Client::instance()->execute($guid, $method, $params));
}

/**
 * Presses the real mouse on an element, moves it away and back, and releases
 * it there, so the browser fires the click a drag must swallow.
 */
function calendarDragRealMouseRoundTrip(mixed $page, string $selector): void
{
    $point = calendarGesture($page, <<<JS
        query('{$selector}').scrollIntoView({ block: 'center' });

        return center('{$selector}');
    JS);

    calendarDragPlaywright($page->page(), 'mouseMove', ['x' => $point['x'], 'y' => $point['y']]);
    calendarDragPlaywright($page->page(), 'mouseDown', ['button' => 'left', 'clickCount' => 1]);
    calendarDragPlaywright($page->page(), 'mouseMove', ['x' => $point['x'] + 30, 'y' => $point['y'] + 20, 'steps' => 5]);
    calendarDragPlaywright($page->page(), 'mouseMove', ['x' => $point['x'], 'y' => $point['y'], 'steps' => 5]);
    calendarDragPlaywright($page->page(), 'mouseUp', ['button' => 'left', 'clickCount' => 1]);
}

/**
 * Holds every PUT the page sends until `window.__releaseHeldRequests()`.
 */
function calendarDragHoldRequests(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__heldRequests = [];
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, ...rest) {
                this.__method = method.toUpperCase();

                return open.call(this, method, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__method !== 'PUT') {
                    return send.call(this, body);
                }

                window.__heldRequests.push(() => send.call(this, body));
            };
            window.__releaseHeldRequests = () => window.__heldRequests.splice(0).forEach((release) => release());
        })()
    JS);
}

/**
 * Sends every PUT at once but holds its answer until `window.__releaseHeldResponses()`,
 * so the request still carries the page it was sent from.
 */
function calendarDragHoldResponses(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__heldResponses = [];
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, ...rest) {
                this.__method = method.toUpperCase();

                return open.call(this, method, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__method === 'PUT') {
                    const onload = this.onload;

                    this.onload = (...args) => window.__heldResponses.push(() => onload.apply(this, args));
                }

                return send.call(this, body);
            };
            window.__releaseHeldResponses = () => window.__heldResponses.splice(0).forEach((release) => release());
        })()
    JS);
}

/**
 * Sends every PUT the page makes to a closed port, so it fails as a network error.
 */
function calendarDragBreakNetwork(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                return open.call(this, method, method.toUpperCase() === 'PUT' ? 'http://127.0.0.1:9/' : url, ...rest);
            };
        })()
    JS);
}

test('a queued post dropped on another channel\'s slot at the same time asks first; cancel leaves it', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $other = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $channel->workspace_id,
        'timezone' => 'UTC',
        'posting_schedule' => $channel->posting_schedule,
    ]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $queuedAt = $day->setTime(9, 25);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $otherSlotKey = calendarDragSlotKey($other, $day->addDay()->setTime(9, 25));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$otherSlotKey}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$otherSlotKey}");
    waitFor($page, '@calendar-custom-time-dialog');

    $page->assertVisible('@calendar-custom-time-dialog');
    $page->click('@calendar-custom-time-cancel');
    waitForGone($page, '@calendar-custom-time-dialog');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-09");
    $post->refresh();
    expect($post->scheduled_at->equalTo($queuedAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertNoJavaScriptErrors();
});

test('a move the server cannot take goes back with the generic error', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    $post->delete();

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, '@calendar-reschedule-error-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('calendar.reschedule.error'));
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and(array_slice(calendarDragAnnouncements($page), -1))->toBe([__('calendar.reschedule.announce.returned')]);
    $page->assertNoJavaScriptErrors();
});

test('a move lost to the network goes back with the generic error', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragBreakNetwork($page);

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitFor($page, '@calendar-reschedule-error-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('calendar.reschedule.error'));
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the click that ends a real mouse drag does not open the post', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $at = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    calendarDragRealMouseRoundTrip($page, "@calendar-post-{$post->id}");
    $opened = calendarGesture($page, <<<JS
        await pause(300);

        return query('@calendar-post-popover-{$post->id}') !== null;
    JS);

    expect($opened)->toBeFalse()
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertMissing("@calendar-post-overlay-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('the click that ends a real mouse drag does not follow the draft link', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['undated' => 1]));
    calendarDragAssertLive($page, "@calendar-undated-{$draft->id}");

    calendarDragRealMouseRoundTrip($page, "@calendar-undated-{$draft->id}");
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-slot-{$key}-11");
    waitFor($page, "@calendar-slot-{$key}-11 @calendar-post-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertPathIs(parse_url(calendarDragWeekUrl($day), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('Esc on the custom time dialog sends the card back and the next confirm still lands', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $queuedAt = $day->setTime(9, 25);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-dialog');
    $page->keys('@calendar-custom-time-dialog', 'Escape');
    waitForGone($page, '@calendar-custom-time-dialog');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-09")
        ->and(array_slice(calendarDragAnnouncements($page), -1))->toBe([__('calendar.reschedule.announce.returned')]);
    $post->refresh();
    expect($post->scheduled_at->equalTo($queuedAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-confirm');
    $page->click('@calendar-custom-time-confirm');
    waitFor($page, "@calendar-slot-{$key}-11 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertNoJavaScriptErrors();
});

test('the custom time dialog, the toast and the announcement use the display zone', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $queuedAt = $day->setTime(9, 25);
    $tokyoFrom = $queuedAt->setTimezone('Asia/Tokyo');
    $tokyoTo = $tokyoFrom->setTime(20, 0);
    $key = $tokyoFrom->format('Y-m-d');
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['tz' => 'Asia/Tokyo']));
    waitFor($page, "@calendar-slot-{$key}-18 @calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-20");
    waitFor($page, '@calendar-custom-time-dialog');

    $page->assertSeeIn('@calendar-custom-time-description', "{$tokyoFrom->format('M j, g:i A')} (Asia/Tokyo)")
        ->assertSeeIn('@calendar-custom-time-description', "{$tokyoTo->format('M j, g:i A')} (Asia/Tokyo)");
    $page->click('@calendar-custom-time-confirm');
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.moved', ['time' => calendarDragWhen($tokyoTo)]));
    expect(array_slice(calendarDragAnnouncements($page), -1))->toBe([
        __('calendar.reschedule.announce.moved', ['from' => calendarDragWhen($tokyoFrom), 'to' => calendarDragWhen($tokyoTo)]),
    ])
        ->and($post->refresh()->scheduled_at->equalTo($tokyoTo))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the custom time dialog follows the user\'s 24-hour clock', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $day = calendarDragDay();
    $tokyoFrom = $day->setTime(9, 25)->setTimezone('Asia/Tokyo');
    $key = $tokyoFrom->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day, ['tz' => 'Asia/Tokyo']));
    waitFor($page, "@calendar-slot-{$key}-18 @calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-20");
    waitFor($page, '@calendar-custom-time-dialog');

    $description = $page->script('document.querySelector(\'[data-testid="calendar-custom-time-description"]\').textContent');

    expect($description)->toContain("{$tokyoFrom->format('M j')}, 18:25 (Asia/Tokyo)")
        ->toContain("{$tokyoFrom->format('M j')}, 20:00 (Asia/Tokyo)")
        ->not->toContain('AM')
        ->not->toContain('PM');
    $page->click('@calendar-custom-time-cancel');
    waitForGone($page, '@calendar-custom-time-dialog');
    $page->assertNoJavaScriptErrors();
});

test('a month drop in another display zone keeps the clock of that zone', function () {
    [$user, $channel] = calendarDragSetup();
    $from = now('Asia/Tokyo')->startOfMonth()->addMonth()->addDays(4)->setTime(8, 30);
    $to = $from->addDays(2);
    $draftDay = $from->addDays(3)->startOfDay();
    $post = calendarDragPost($channel, $from->utc());
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $from->format('Y-m-d'), 'tz' => 'Asia/Tokyo', 'undated' => 1]));
    waitFor($page, "@calendar-day-{$from->format('Y-m-d')} @calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$to->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$to->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($to))->toBeTrue();

    calendarDragAssertLive($page, "@calendar-undated-{$draft->id}");
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-day-{$draftDay->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$draftDay->format('Y-m-d')} @calendar-post-{$draft->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($draft->refresh()->scheduled_at->equalTo($draftDay->setTime(9, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a month drop on today is refused when five minutes from now is already tomorrow', function () {
    [$user, $channel] = calendarDragSetup();
    $late = now('UTC')->startOfMonth()->addMonth()->addDays(14)->setTime(23, 57);
    $this->travelTo($late);
    $today = $late->format('Y-m-d');
    $at = $late->addDay()->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $today]));
    calendarDragPlaywright($page->page()->context(), 'clockInstall', ['timeNumber' => $late->getTimestampMs()]);
    $page->page()->reload();
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $accepts = calendarGesture($page, <<<JS
        query('@calendar-post-{$post->id}').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-day-{$today}'));
        const over = query('@calendar-day-{$today}').classList.contains('bg-primary/15');
        const clock = Date.now();
        await up(center('@calendar-day-{$today}'));

        return { over, clock };
    JS);
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($accepts['over'])->toBeFalse()
        ->and(abs($accepts['clock'] - $late->getTimestampMs()))->toBeLessThan(60_000)
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-day-{$at->format('Y-m-d')}")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();

    $noon = $late->setTime(12, 0);
    $this->travelTo($noon);
    calendarDragPlaywright($page->page()->context(), 'clockSetSystemTime', ['timeNumber' => $noon->getTimestampMs()]);
    $page->page()->reload();
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$today}");
    waitFor($page, "@calendar-day-{$today} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($noon->addMinutes(5)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a post due within a minute and a draft without a channel cannot be dragged', function () {
    [$user, $channel] = calendarDragSetup();
    $soon = now('UTC')->addSeconds(30);
    $due = calendarDragPost($channel, $soon);
    $movable = calendarDragPost($channel, $soon->addHours(2));
    $edge = calendarDragPost($channel, now('UTC')->addMinutes(2));
    $channelless = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'content' => 'No channel yet',
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $soon->format('Y-m-d'), 'undated' => 1]));
    waitFor($page, "@calendar-post-{$due->id}");
    waitFor($page, "@calendar-undated-{$channelless->id}");
    calendarDragAssertLive($page, "@calendar-post-{$movable->id}");
    calendarDragAssertLive($page, "@calendar-undated-{$draft->id}");

    $draggable = fn (string $selector): ?string => $page->script("document.querySelector('[data-testid=\"{$selector}\"]').dataset.calendarDraggable ?? null");

    expect($channelless->platform)->toBeNull()
        ->and($draggable("calendar-post-{$movable->id}"))->toBe('true')
        ->and($draggable("calendar-post-{$edge->id}"))->toBe('true')
        ->and($draggable("calendar-undated-{$draft->id}"))->toBe('true')
        ->and($draggable("calendar-post-{$due->id}"))->toBeNull()
        ->and($draggable("calendar-undated-{$channelless->id}"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('the card a member who needs approval moved is no longer draggable', function () {
    [$owner, $channel] = calendarDragSetup();
    $member = User::factory()->create(['account_id' => $owner->account_id, 'timezone' => 'UTC']);
    $channel->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $channel->workspace_id]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 0), attributes: ['user_id' => $member->id]);
    $this->actingAs($member);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-reschedule-approval-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitForState($page, "@calendar-post-{$post->id}", 'element.dataset.calendarDraggable === undefined');

    expect($post->refresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]').dataset.calendarDraggable ?? null"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('a pointercancel, a hidden tab or a period change ends a drag without sending anything', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 30);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $page->script("document.querySelector('[data-testid=\"calendar-slot-{$key}-12\"]').scrollIntoView({ block: 'center' })");

    $steps = calendarGesture($page, <<<JS
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const before = state().dragging;
        fire('pointercancel', center('@calendar-slot-{$key}-12'), 0);
        await frame();
        const cancelled = state().dragging;
        await up(center('@calendar-slot-{$key}-12'));

        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const again = state().dragging;
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        document.dispatchEvent(new Event('visibilitychange'));
        delete document.visibilityState;
        await frame();
        const hidden = state().dragging;
        await up(center('@calendar-slot-{$key}-12'));

        return { before, cancelled, again, hidden };
    JS);

    waitForGone($page, '@calendar-grid[aria-busy]');
    expect($steps)->toBe(['before' => true, 'cancelled' => false, 'again' => true, 'hidden' => false])
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();

    $nextKey = $day->addWeek()->format('Y-m-d');
    $dragging = calendarGesture($page, <<<JS
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const dragging = state().dragging;
        query('@calendar-next').click();

        return dragging;
    JS);
    waitFor($page, "@calendar-slot-{$nextKey}-12");
    waitForGone($page, '@calendar-drag-preview');
    calendarGesture($page, <<<JS
        query('@calendar-slot-{$nextKey}-12').scrollIntoView({ block: 'center' });
        await move(center('@calendar-slot-{$nextKey}-12'));
        await up(center('@calendar-slot-{$nextKey}-12'));
    JS);
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($dragging)->toBeTrue()
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertMissing('@calendar-drag-preview')
        ->assertNoJavaScriptErrors();
});

test('the card sits in its new cell while the move is on its way and stays there', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragHoldRequests($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitForCondition($page, 'window.__heldRequests.length === 1');

    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-12")
        ->and($page->script('document.querySelector(\'[data-testid="calendar-grid"]\').getAttribute(\'aria-busy\')'))->toBe('true')
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();

    $page->script('window.__releaseHeldRequests()');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-12")
        ->and($post->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a card whose move is on its way cannot be dragged again until it lands', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragHoldRequests($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitForCondition($page, 'window.__heldRequests.length === 1');

    $draggedAgain = calendarGesture($page, <<<JS
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-14'));
        const dragging = state().dragging;
        await up(center('@calendar-slot-{$key}-14'));

        return dragging;
    JS);

    expect($draggedAgain)->toBeFalse()
        ->and($page->script('window.__heldRequests.length'))->toBe(1);

    $page->script('window.__releaseHeldRequests()');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-12")
        ->and($post->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue();

    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-14");
    waitForCondition($page, 'window.__heldRequests.length === 1');
    $page->script('window.__releaseHeldRequests()');
    waitFor($page, "@calendar-slot-{$key}-14 @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(14, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a post that becomes due before the move reaches the server goes back with the reason', function () {
    [$user, $channel] = calendarDragSetup();
    $at = now('UTC')->addMinutes(3)->startOfMinute();
    $target = $at->addDay()->startOfHour();
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'days', 'day' => $at->format('Y-m-d')]));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    $this->travelTo($at->subSeconds(30));

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$target->format('Y-m-d')}-{$target->format('H')}");
    waitFor($page, '@calendar-reschedule-error-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('calendar.reschedule.errors.publishing_soon'));
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$at->format('Y-m-d')}-{$at->format('H')}")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a custom post dropped on a posting time of its channel in the month grid goes into the queue at that time', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '15:25');
    $day = calendarDragDay();
    $slotAt = $day->addDay()->setTime(15, 25);
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $post = calendarDragPost($channel, $day->setTime(13, 30));
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d')]));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-day-{$slotAt->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertNoJavaScriptErrors();
});

test('a queued post dropped on a free posting time of its channel in the month grid stays in the queue there without asking', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $slotAt = $day->addDay()->setTime(9, 25);
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d')]));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-day-{$slotAt->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertNoJavaScriptErrors();
});

/**
 * The next US daylight saving change of the given rule ("first sunday of
 * november", "second sunday of march") at least a few days away, in New York.
 */
function calendarDragNextDstSunday(string $rule): CarbonImmutable
{
    $year = now('UTC')->year;
    $sunday = CarbonImmutable::parse("{$rule} {$year}", 'America/New_York');

    return $sunday->subDays(3)->isAfter(now('UTC')) ? $sunday : CarbonImmutable::parse("{$rule} ".($year + 1), 'America/New_York');
}

test('a month drop across the end of daylight saving keeps the wall clock', function (string $userZone, array $query) {
    [$user, $channel] = calendarDragSetup($userZone);
    $user->update(['week_starts_on' => WeekStart::Monday]);
    $sunday = calendarDragNextDstSunday('first sunday of november');
    $from = $sunday->subDays(2)->setTime(10, 0);
    $to = $sunday->addDay()->setTime(10, 0);
    $post = calendarDragPost($channel, $from->utc());
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $sunday->format('Y-m-d'), ...$query]));
    waitFor($page, "@calendar-day-{$from->format('Y-m-d')} @calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$to->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$to->format('Y-m-d')} @calendar-post-{$post->id}");
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($from->utc()->format('H:i'))->toBe('14:00')
        ->and($post->refresh()->scheduled_at->utc()->format('Y-m-d H:i'))->toBe($to->utc()->format('Y-m-d').' 15:00');
    $page->assertNoJavaScriptErrors();
})->with([
    'user zone' => ['America/New_York', []],
    'display zone' => ['UTC', ['tz' => 'America/New_York']],
]);

test('the hour skipped by the start of daylight saving is not a drop target', function () {
    [$user, $channel] = calendarDragSetup('America/New_York');
    $sunday = calendarDragNextDstSunday('second sunday of march');
    $key = $sunday->format('Y-m-d');
    $post = calendarDragPost($channel, $sunday->setTime(10, 0)->utc());
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($sunday));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $keys = $page->script("[...document.querySelectorAll('[data-testid^=\"calendar-slot-{$key}-\"]')].map((cell) => cell.dataset.calendarDrop ?? null)");
    $dropKeys = array_values(array_filter($keys));

    expect($keys)->toHaveCount(24)
        ->and($keys[2])->toBeNull()
        ->and($dropKeys)->toHaveCount(23)
        ->and(array_unique($dropKeys))->toHaveCount(23);
    $page->assertNoJavaScriptErrors();
});

test('the hour skipped by the start of daylight saving is not highlighted and offers no new post', function () {
    [$user, $channel] = calendarDragSetup('America/New_York');
    $sunday = calendarDragNextDstSunday('second sunday of march');
    $key = $sunday->format('Y-m-d');
    $post = calendarDragPost($channel, $sunday->setTime(10, 0)->utc());
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($sunday));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $cells = $page->script(<<<JS
        ({
            skippedOver: document.querySelector('[data-testid="calendar-slot-{$key}-02"]').classList.contains('bg-primary/15'),
            skippedAdd: document.querySelector('[data-testid="calendar-add-{$key}-02"]') !== null,
            nextAdd: document.querySelector('[data-testid="calendar-add-{$key}-03"]') !== null,
        })
    JS);

    expect($cells)->toBe(['skippedOver' => false, 'skippedAdd' => false, 'nextAdd' => true]);
    $page->assertNoJavaScriptErrors();
});

test('a month drop whose clock falls in the hour skipped by daylight saving is refused', function () {
    [$user, $channel] = calendarDragSetup('America/New_York');
    $sunday = calendarDragNextDstSunday('second sunday of march');
    $from = $sunday->subDays(2)->setTime(2, 30);
    $post = calendarDragPost($channel, $from->utc());
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $sunday->format('Y-m-d')]));
    waitFor($page, "@calendar-day-{$from->format('Y-m-d')} @calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragHoldRequests($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$sunday->format('Y-m-d')}");
    waitForGone($page, '@calendar-drag-preview');

    expect($page->script('window.__heldRequests.length'))->toBe(0)
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-day-{$from->format('Y-m-d')}")
        ->and($post->refresh()->scheduled_at->equalTo($from))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the grid auto-scrolls near its top edge', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(22, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $scroll = calendarGesture($page, <<<JS
        const grid = query('@calendar-time-grid');
        grid.scrollTop = grid.scrollHeight;
        await frame();
        const before = grid.scrollTop;
        const box = grid.getBoundingClientRect();
        const start = center('@calendar-post-{$post->id}');
        await down(start);
        await move({ x: start.x, y: box.top + 10 });
        for (let i = 0; i < 60 && grid.scrollTop === before; i++) {
            await pause(50);
        }

        return { before, after: grid.scrollTop };
    JS);

    expect($scroll['before'])->toBeGreaterThan(0)
        ->and($scroll['after'])->toBeLessThan($scroll['before']);

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertNoJavaScriptErrors();
});

test('the month grid auto-scrolls near its bottom edge', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $scroll = calendarGesture($page, <<<JS
        const grid = query('@calendar-month-grid');
        query('@calendar-post-{$post->id}').scrollIntoView({ block: 'center' });
        grid.scrollTop = 0;
        await frame();
        const box = grid.getBoundingClientRect();
        const start = center('@calendar-post-{$post->id}');
        await down(start);
        await move({ x: start.x, y: box.bottom - 10 });
        for (let i = 0; i < 60 && grid.scrollTop === 0; i++) {
            await pause(50);
        }

        return { overflows: grid.scrollHeight > grid.clientHeight, after: grid.scrollTop };
    JS);

    expect($scroll['overflows'])->toBeTrue()
        ->and($scroll['after'])->toBeGreaterThan(0);

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertNoJavaScriptErrors();
});

test('the context menu does not open during a drag', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");

    $prevented = calendarGesture($page, <<<JS
        const contextMenu = (point) => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: point.x, clientY: point.y });
            document.elementFromPoint(point.x, point.y).dispatchEvent(event);

            return event.defaultPrevented;
        };
        query('@calendar-slot-{$key}-12').scrollIntoView({ block: 'center' });
        const idle = contextMenu(center('@calendar-post-{$post->id}'));
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const dragging = state().dragging && contextMenu(center('@calendar-slot-{$key}-12'));

        return { idle, dragging };
    JS);

    expect($prevented)->toBe(['idle' => false, 'dragging' => true]);

    $page->keys("@calendar-post-{$post->id}", 'Escape');
    waitForGone($page, '@calendar-drag-preview');
    $page->assertNoJavaScriptErrors();
});

test('a move cancelled by going Back sends nothing more and announces the return once', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $at = $day->setTime(10, 0);
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day->subWeek()));
    $page->click('@calendar-next');
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    calendarDragHoldRequests($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitForCondition($page, 'window.__heldRequests.length === 1');

    $page->script('history.back()');
    waitForCondition($page, "window.__announcements.includes('".__('calendar.reschedule.announce.returned')."')");
    waitForGone($page, "@calendar-post-{$post->id}");

    expect(array_count_values(calendarDragAnnouncements($page))[__('calendar.reschedule.announce.returned')])->toBe(1)
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertMissing('@calendar-reschedule-error-toast')
        ->assertMissing('@calendar-reschedule-success-toast')
        ->assertNoJavaScriptErrors();
});

test('a move still saving when the week changes does not bring the old week back', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $nextWeek = $day->addWeek()->startOfWeek()->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragHoldResponses($page);
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-12");
    waitForCondition($page, 'window.__heldResponses.length === 1');

    $page->click('@calendar-next');
    waitFor($page, "@calendar-column-{$nextWeek}");
    $page->script('window.__releaseHeldResponses()');
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(12, 0)))->toBeTrue();
    $page->assertScript("new URLSearchParams(location.search).get('week')", $nextWeek)
        ->assertPresent("@calendar-column-{$nextWeek}")
        ->assertMissing("@calendar-column-{$key}")
        ->assertNoJavaScriptErrors();
});

test('a move by a member who needs approval says so even when the status filter hides the post afterwards', function () {
    [$owner, $channel] = calendarDragSetup();
    $member = User::factory()->create(['account_id' => $owner->account_id, 'timezone' => 'UTC']);
    $channel->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $channel->workspace_id]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 0), attributes: ['user_id' => $member->id]);
    $this->actingAs($member);

    $page = visit(calendarDragWeekUrl($day, ['status' => 'scheduled']));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitForGoneOrShown($page, gone: '@calendar-grid[aria-busy]', shown: '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertPresent('@calendar-reschedule-approval-toast')
        ->assertMissing('@calendar-reschedule-success-toast');
    expect($post->refresh()->status)->toBe(PostStatus::PendingApproval);
    $page->assertNoJavaScriptErrors();
});

test('the custom time dialog writes the dates in the order of the user\'s language', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $user->update(['locale' => Locale::PortugueseBrazil]);
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $queuedAt = $day->setTime(9, 25);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-dialog');

    $expected = $page->script("new Intl.DateTimeFormat('pt-BR', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date('{$queuedAt->format('Y-m-d\\TH:i:s')}Z'))");

    expect($page->script('document.querySelector(\'[data-testid="calendar-custom-time-description"]\').textContent'))
        ->toContain("{$expected}, 9:25 AM (UTC)");
    $page->assertNoJavaScriptErrors();
});

test('a queued post dropped on its channel\'s slot past the queue window asks first and becomes custom', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay()->addWeeks(15);
    $slotAt = $day->addDay()->setTime(9, 25);
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $post = calendarDragPost($channel, $day->setTime(9, 25), mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, '@calendar-custom-time-dialog');
    $page->click('@calendar-custom-time-confirm');
    waitFor($page, '@calendar-reschedule-success-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertSeeIn('@calendar-reschedule-success-toast', __('calendar.reschedule.toast.unqueued_suffix'));
    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertNoJavaScriptErrors();
});

test('the custom time dialog shows the year of a date in another year', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = now('UTC')->addYear()->startOfYear()->addWeeks(2)->startOfWeek()->addDays(2)->toImmutable();
    $key = $day->format('Y-m-d');
    $queuedAt = $day->setTime(9, 25);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    waitFor($page, '@calendar-custom-time-dialog');

    $page->assertSeeIn('@calendar-custom-time-description', "{$queuedAt->format('M j, Y, g:i A')} (UTC)")
        ->assertNoJavaScriptErrors();
});

test('a post that comes due while it is being dragged goes back without a request', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $post = calendarDragPost($channel, $day->setTime(10, 30));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragAssertLive($page, "@calendar-post-{$post->id}");
    calendarDragWatchAnnouncer($page);
    $due = $day->setTime(10, 30)->getTimestampMs();

    calendarGesture($page, <<<JS
        query('@calendar-post-{$post->id}').scrollIntoView({ block: 'center' });
        await down(center('@calendar-post-{$post->id}'));
        await move(center('@calendar-slot-{$key}-12'));
        const RealDate = Date;
        const shift = {$due} - RealDate.now();
        window.Date = class extends RealDate {
            constructor(...args) {
                super(...(args.length ? args : [RealDate.now() + shift]));
            }

            static now() {
                return RealDate.now() + shift;
            }
        };
        await up(center('@calendar-slot-{$key}-12'));
    JS);
    waitFor($page, "@calendar-slot-{$key}-10 @calendar-post-{$post->id}");

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(10, 30)))->toBeTrue()
        ->and(calendarDragAnnouncements($page))->toContain(__('calendar.reschedule.announce.returned'));
    $page->assertMissing('@calendar-reschedule-success-toast')
        ->assertMissing('@calendar-reschedule-error-toast')
        ->assertNoJavaScriptErrors();
});

test('an older move answered after a newer one does not put the newer card back', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $key = $day->format('Y-m-d');
    $first = calendarDragPost($channel, $day->setTime(10, 0));
    $second = calendarDragPost($channel, $day->setTime(11, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    calendarDragAssertLive($page, "@calendar-post-{$first->id}");
    calendarDragAssertLive($page, "@calendar-post-{$second->id}");
    calendarDragHoldResponses($page);

    calendarDragOnto($page, "@calendar-post-{$first->id}", "@calendar-slot-{$key}-13");
    waitForCondition($page, 'window.__heldResponses.length === 1');
    calendarDragOnto($page, "@calendar-post-{$second->id}", "@calendar-slot-{$key}-14");
    waitForCondition($page, 'window.__heldResponses.length === 2');

    $page->script('window.__heldResponses[1]()');
    waitFor($page, '@calendar-reschedule-success-toast');
    $page->script('window.__heldResponses[0]()');
    waitForGone($page, '@calendar-grid[aria-busy]');
    waitFor($page, "@calendar-slot-{$key}-13 @calendar-post-{$first->id}");
    waitFor($page, "@calendar-slot-{$key}-14 @calendar-post-{$second->id}");

    expect(calendarDragCellOf($page, $first))->toBe("calendar-slot-{$key}-13")
        ->and(calendarDragCellOf($page, $second))->toBe("calendar-slot-{$key}-14")
        ->and($second->refresh()->scheduled_at->equalTo($day->setTime(14, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a No Date draft dropped on a day while the status filter hides drafts says it moved, not that it went for approval', function () {
    [$user, $channel] = calendarDragSetup();
    $day = now('UTC')->startOfMonth()->addMonth()->addDays(4)->startOfDay();
    $draft = calendarDragPost($channel, null, PostStatus::Draft);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $day->format('Y-m-d'), 'undated' => 1, 'status' => 'scheduled']));
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragOnto($page, "@calendar-undated-{$draft->id}", "@calendar-day-{$day->format('Y-m-d')}");
    waitForGoneOrShown($page, gone: '@calendar-grid[aria-busy]', shown: '@calendar-reschedule-approval-toast');
    waitForGone($page, '@calendar-grid[aria-busy]');

    $page->assertMissing('@calendar-reschedule-approval-toast')
        ->assertPresent('@calendar-reschedule-success-toast');
    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(9, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});
