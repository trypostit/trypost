<?php

declare(strict_types=1);

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

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

    if ($slotTime !== null) {
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

function calendarDragFinished(mixed $page): void
{
    waitForGone($page, '@calendar-grid[aria-busy]');
}

function calendarDragCellOf(mixed $page, Post $post): ?string
{
    return $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]')?.closest('[data-testid^=\"calendar-slot-\"], [data-testid^=\"calendar-day-\"]')?.dataset.testid ?? null");
}

/**
 * Drags with both ends on screen: a scroll between mouse down and the first
 * move cancels a native drag, and Playwright scrolls the target into view.
 */
function calendarDragOnto(mixed $page, string $from, string $to): void
{
    $source = jsSelector($from);
    $target = jsSelector($to);

    $page->script("document.querySelector({$target}).scrollIntoView({ block: 'center' }); document.querySelector({$source}).scrollIntoView({ block: 'nearest' });");
    $page->drag($from, $to);
}

function calendarDragAssertLive(mixed $page, string $selector): void
{
    waitForState($page, $selector, "element.getAttribute('draggable') === 'true'");

    expect($page->script('document.querySelector('.jsSelector($selector).').getAttribute("draggable")'))->toBe('true');
}

function calendarDragIsBusy(mixed $page): bool
{
    return $page->script('document.querySelector(\'[data-testid="calendar-grid"]\').hasAttribute("aria-busy")');
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
    calendarDragFinished($page);

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
    calendarDragFinished($page);

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

    $page->assertSeeIn('@calendar-custom-time-dialog', __('calendar.reschedule.custom_time.title'))
        ->assertSeeIn('@calendar-custom-time-description', "{$queuedAt->format('M j g:i A')} (UTC)")
        ->assertSeeIn('@calendar-custom-time-description', "{$day->setTime(11, 0)->format('M j g:i A')} (UTC)");
    expect(calendarDragIsBusy($page))->toBeFalse();

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

    expect(calendarDragIsBusy($page))->toBeFalse();
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
    calendarDragFinished($page);

    $post->refresh();
    expect($post->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertMissing('@calendar-custom-time-dialog')
        ->assertNoJavaScriptErrors();
});

test('don\'t show again skips the dialog next time', function () {
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
    calendarDragFinished($page);

    expect($page->script('window.localStorage.getItem("calendar.skipCustomTimeConfirm")'))->toBe('true');
    waitFor($page, '@calendar-posting-slot-'.calendarDragSlotKey($channel, $day->setTime(9, 25)));

    waitFor($page, "@calendar-post-{$second->id}");
    calendarDragOnto($page, "@calendar-post-{$second->id}", "@calendar-slot-{$nextKey}-11");
    waitFor($page, "@calendar-slot-{$nextKey}-11 @calendar-post-{$second->id}");
    calendarDragFinished($page);

    $page->assertMissing('@calendar-custom-time-dialog');
    $second->refresh();
    expect($second->scheduled_at->equalTo($day->addDay()->setTime(11, 0)))->toBeTrue()
        ->and($second->schedule_mode)->toBe(ScheduleMode::Custom);
    $page->assertNoJavaScriptErrors();
});

test('dropping a queued post on a free slot of its channel keeps it queued without asking', function () {
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
    calendarDragFinished($page);

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
    expect(calendarDragIsBusy($page))->toBeFalse();

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$yesterday->format('Y-m-d')}-{$now->format('H')}");
    expect(calendarDragIsBusy($page))->toBeFalse()
        ->and(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$at->format('Y-m-d')}-{$at->format('H')}");

    $month = visit(route('app.calendar', ['view' => 'month', 'month' => $yesterday->format('Y-m-d'), 'undated' => 1]));
    waitFor($month, "@calendar-undated-{$draft->id}");
    calendarDragAssertLive($month, "@calendar-undated-{$draft->id}");
    calendarDragOnto($month, "@calendar-undated-{$draft->id}", "@calendar-day-{$yesterday->format('Y-m-d')}");
    expect(calendarDragIsBusy($month))->toBeFalse();
    $month->assertVisible("@calendar-undated-{$draft->id}");

    expect($post->refresh()->scheduled_at->equalTo($at))->toBeTrue()
        ->and($draft->refresh()->scheduled_at)->toBeNull();
    $page->assertMissing('@calendar-reschedule-error-toast');

    $validAt = $at->hour > 0 ? $at->subHour() : $at->addHour();
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$validAt->format('Y-m-d')}-{$validAt->format('H')}");
    calendarDragFinished($page);

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
    calendarDragFinished($page);

    expect($post->refresh()->scheduled_at->equalTo($to))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('month drop on today after that time lands five minutes from now', function () {
    $timezone = collect(['UTC', 'Asia/Tokyo', 'America/New_York', 'Asia/Kolkata', 'Pacific/Auckland', 'America/Los_Angeles', 'Europe/Berlin'])
        ->first(fn (string $zone): bool => now($zone)->hour >= 2 && now($zone)->hour <= 21 && ! now($zone)->isLastOfMonth());
    [$user, $channel] = calendarDragSetup($timezone);
    $local = now($timezone);
    $at = $local->addDay()->setTime($local->subHour()->hour, $local->minute)->startOfMinute()->utc();
    $post = calendarDragPost($channel, $at);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $local->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-day-{$local->format('Y-m-d')}");
    waitFor($page, "@calendar-day-{$local->format('Y-m-d')} @calendar-post-{$post->id}");
    calendarDragFinished($page);

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
    calendarDragFinished($page);
    waitForGone($page, "@calendar-undated-{$draft->id}");

    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);

    calendarDragOnto($page, "@calendar-post-{$draft->id}", "@calendar-slot-{$key}-13");
    waitFor($page, "@calendar-slot-{$key}-13 @calendar-post-{$draft->id}");
    calendarDragFinished($page);

    $draft->refresh();
    expect($draft->scheduled_at->equalTo($day->setTime(13, 0)))->toBeTrue()
        ->and($draft->status)->toBe(PostStatus::Draft);

    calendarDragOnto($page, "@calendar-post-{$draft->id}", '@calendar-undated-panel');
    waitFor($page, "@calendar-undated-{$draft->id}");
    calendarDragFinished($page);
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
    calendarDragFinished($page);

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
    calendarDragOnto($page, "@calendar-post-{$post->id}", '@calendar-undated-panel');

    expect(calendarDragIsBusy($page))->toBeFalse()
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$day->format('Y-m-d')}-10");
    $page->assertMissing("@calendar-undated-{$post->id}");

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$day->format('Y-m-d')}-11");
    calendarDragFinished($page);

    expect($post->refresh()->scheduled_at->equalTo($day->setTime(11, 0)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('published posts are not draggable', function () {
    [$user, $channel] = calendarDragSetup();
    $day = calendarDragDay();
    $published = calendarDragPost($channel, $day->setTime(8, 0), PostStatus::Published, attributes: ['published_at' => $day->setTime(8, 0)]);
    $failed = calendarDragPost($channel, $day->setTime(9, 0), PostStatus::Failed);
    $pending = calendarDragPost($channel, $day->setTime(10, 0), PostStatus::PendingApproval);
    $recurring = calendarDragPost($channel, $day->setTime(11, 0), attributes: [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
    ]);
    $movable = calendarDragPost($channel, $day->setTime(12, 0));
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-post-{$movable->id}");
    waitForState($page, "@calendar-post-{$movable->id}", "element.getAttribute('draggable') === 'true'");

    $draggable = fn (Post $post): ?string => $page->script("document.querySelector('[data-testid=\"calendar-post-{$post->id}\"]').getAttribute('draggable')");

    expect($draggable($movable))->toBe('true')
        ->and($draggable($published))->toBeNull()
        ->and($draggable($failed))->toBeNull()
        ->and($draggable($pending))->toBeNull()
        ->and($draggable($recurring))->toBeNull();
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
    calendarDragFinished($page);

    expect($post->refresh()->scheduled_at->equalTo($tokyoDay->setTime(10, 0)))->toBeTrue()
        ->and($post->scheduled_at->utc()->format('H:i'))->toBe('01:00');
    $page->assertNoJavaScriptErrors();
});

test('a slot taken meanwhile rolls back with an error toast', function () {
    [$user, $channel] = calendarDragSetup(slotTime: '09:25');
    $day = calendarDragDay();
    $queuedAt = $day->setTime(9, 25);
    $slotAt = $day->addDay()->setTime(9, 25);
    $slotKey = calendarDragSlotKey($channel, $slotAt);
    $post = calendarDragPost($channel, $queuedAt, mode: ScheduleMode::Queue);
    $this->actingAs($user);

    $page = visit(calendarDragWeekUrl($day));
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@calendar-post-{$post->id}");

    $taken = false;
    Post::retrieved(function (Post $retrieved) use (&$taken, $post, $channel, $slotAt): void {
        if ($taken || ! $retrieved->is($post)) {
            return;
        }

        $probe = Cache::lock("queue:{$channel->id}");

        if ($probe->get()) {
            $probe->release();

            return;
        }

        $taken = true;
        calendarDragPost($channel, $slotAt);
    });

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-posting-slot-{$slotKey}");
    waitFor($page, '@calendar-reschedule-error-toast');
    calendarDragFinished($page);

    $page->assertSeeIn('@calendar-reschedule-error-toast', __('posts.errors.queue_order_stale'));
    waitFor($page, "@calendar-slot-{$day->format('Y-m-d')}-09 @calendar-post-{$post->id}");
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$day->format('Y-m-d')}-09");
    $post->refresh();
    expect($taken)->toBeTrue()
        ->and($post->scheduled_at->equalTo($queuedAt))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
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

    expect(calendarDragIsBusy($page))->toBeFalse()
        ->and($post->refresh()->scheduled_at->equalTo($at))->toBeTrue();
    expect(calendarDragCellOf($page, $post))->toBe("calendar-slot-{$key}-10");

    calendarDragOnto($page, "@calendar-post-{$post->id}", "@calendar-slot-{$key}-11");
    calendarDragFinished($page);

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
