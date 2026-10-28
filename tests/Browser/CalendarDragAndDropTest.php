<?php

declare(strict_types=1);

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

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

test('dragging onto a slot takes the slot\'s exact time', function () {
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
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
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
        ->assertSeeIn('@calendar-custom-time-description', "{$queuedAt->format('M j g:i A')} (UTC)")
        ->assertSeeIn('@calendar-custom-time-description', "{$day->setTime(11, 0)->format('M j g:i A')} (UTC)");
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

test('dropping a queued post on a free slot of its channel becomes custom at that slot without asking', function () {
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

    $page->assertMissing('@calendar-custom-time-dialog');
    $post->refresh();
    expect($post->scheduled_at->equalTo($slotAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
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

test('a refused time shows the calendar error, not the raw validation message', function () {
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

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('calendar.reschedule.error'));
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10")
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
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
    return $dateOnly
        ? $at->format('l, F j')
        : __('calendar.reschedule.announce.date_time', ['date' => $at->format('l, F j'), 'time' => $at->format('g:i A')]);
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

test('a draft from the No Date panel announces its channel and new time and toasts as a draft', function () {
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
