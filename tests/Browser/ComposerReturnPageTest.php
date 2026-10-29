<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Arr;

function composerReturnPageChannel(): SocialAccount
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $weekday) {
        $schedule = $schedule->withTime($weekday, '15:00');
    }

    test()->actingAs($user);

    return SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);
}

function assertComposerReturnedTo(mixed $page, string $url): void
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    ksort($query);
    $sortedSearch = "[...new URLSearchParams(window.location.search)].sort(([a], [b]) => a.localeCompare(b)).map(([key, value]) => `\${key}=\${value}`).join('&')";
    $expected = Arr::query($query);

    waitForGone($page, '@post-composer-dialog');
    waitForCondition($page, "{$sortedSearch} === ".json_encode($expected));

    $page->assertPathIs((string) parse_url($url, PHP_URL_PATH))
        ->assertScript($sortedSearch, $expected)
        ->assertNoJavaScriptErrors();
}

test('scheduling a post from a free calendar slot stays on the calendar as it was', function (string $view, string $period) {
    $channel = composerReturnPageChannel();
    $day = now('UTC')->startOfWeek()->addWeek()->addDays(2);
    $slotKey = "{$channel->id}-{$day->copy()->setTime(15, 0)->getTimestamp()}";
    $calendar = route('app.calendar', ['view' => $view, $period => $day->format('Y-m-d'), 'slots' => 1]);

    $page = visit($calendar)->resize(1280, 900);
    waitFor($page, "@calendar-posting-slot-{$slotKey}");
    $page->hover("@calendar-posting-slot-{$slotKey}")
        ->click("@calendar-posting-slot-{$slotKey}");
    waitFor($page, "@composer-caption-{$channel->id}");
    $page->fill("@composer-caption-{$channel->id}", 'From a calendar slot');
    waitFor($page, '@composer-submit');
    $page->click('@composer-submit');

    assertComposerReturnedTo($page, $calendar);
    expect(Post::query()->where('social_account_id', $channel->id)->sole()->scheduled_at->equalTo($day->copy()->setTime(15, 0)))->toBeTrue();
})->with([
    'week' => ['week', 'week'],
    'month' => ['month', 'month'],
]);

test('a post started from the sidebar stays on the page it was started from', function (string $origin) {
    $channel = composerReturnPageChannel();
    $url = match ($origin) {
        'app.channels.calendar' => route($origin, ['account' => $channel, 'view' => 'week', 'slots' => 1]),
        default => route($origin),
    };

    $page = visit($url)->resize(1280, 900);
    waitFor($page, "@sidebar-channel-row-{$channel->id}");
    $page->hover("@sidebar-channel-row-{$channel->id}")
        ->click("@sidebar-channel-{$channel->id}-new-post");
    waitFor($page, "@composer-caption-{$channel->id}");
    $page->fill("@composer-caption-{$channel->id}", 'From the sidebar');
    waitFor($page, '@composer-save-draft');
    $page->click('@composer-save-draft');

    assertComposerReturnedTo($page, $url);
    expect(Post::query()->where('social_account_id', $channel->id)->sole()->content)->toContain('From the sidebar');
})->with([
    'the channel calendar' => ['app.channels.calendar'],
    'insights' => ['app.insights'],
]);
