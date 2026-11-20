<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function postingStreakBrowserWeeks(SocialAccount $account, array $weeksAgo): void
{
    $thisWeek = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);

    foreach ($weeksAgo as $ago) {
        AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $account->platform,
            'provider_published_at' => $thisWeek->subWeeks($ago),
        ]);
    }
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Storage::fake();

    $user = User::factory()->create(['timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $this->user = $user->fresh();
    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);

    $this->actingAs($this->user);
});

test('the sidebar shows no streak badge without a streak', function () {
    $page = visit(route('app.insights'));
    waitFor($page, '@sidebar-logo');

    $page->assertMissing('@posting-streak-badge')
        ->assertNoJavaScriptErrors();
});

test('the sidebar badge opens the streak and its nudge', function () {
    postingStreakBrowserWeeks($this->instagram, [0, 1, 2]);

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-streak-badge');
    $page->assertSeeIn('@posting-streak-count', '3')
        ->click('@posting-streak-badge');
    waitForOpen($page, '@posting-streak-popover');

    $page->assertSeeIn('@posting-streak-title', '3 week streak')
        ->assertSeeIn('@posting-streak-nudge', 'Schedule a post for next week')
        ->assertNoJavaScriptErrors();
});

test('a streak without a post this week asks for one this week', function () {
    postingStreakBrowserWeeks($this->instagram, [1, 2]);

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-streak-badge');
    $page->click('@posting-streak-badge');
    waitForOpen($page, '@posting-streak-popover');

    $page->assertSeeIn('@posting-streak-nudge', "You haven't posted this week yet")
        ->assertNoJavaScriptErrors();
});

test('schedule post opens the composer', function () {
    postingStreakBrowserWeeks($this->instagram, [0]);

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-streak-badge');
    $page->click('@posting-streak-badge');
    waitForOpen($page, '@posting-streak-popover');
    $page->click('@posting-streak-schedule');
    waitForOpen($page, '@post-composer-dialog');

    $page->assertNoJavaScriptErrors();
});

test('sharing the streak opens the composer with its post and image', function () {
    postingStreakBrowserWeeks($this->instagram, [0, 1]);

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-streak-badge');
    $page->click('@posting-streak-badge');
    waitForOpen($page, '@posting-streak-popover');
    $page->click('@posting-streak-share');
    waitForState($page, '@composer-base-content', "element.value === \"I've reached a 2-week posting streak with TryPost!\"");
    waitForCount($page, '@composer-media-item', 1);

    $page->assertNoJavaScriptErrors();
});

test('closing the streak while its image uploads leaves the page as it is', function () {
    postingStreakBrowserWeeks($this->instagram, [0, 1]);

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-streak-badge');
    $page->script('(() => {
        const send = XMLHttpRequest.prototype.send;
        const held = [];
        window.__heldUploads = held;
        window.__releaseUploads = () => {
            XMLHttpRequest.prototype.send = send;
            held.forEach((release) => release());
        };
        XMLHttpRequest.prototype.send = function (...body) {
            held.push(() => send.apply(this, body));
        };
    })()');
    $page->click('@posting-streak-badge');
    waitForOpen($page, '@posting-streak-popover');
    $page->click('@posting-streak-share');
    waitForCondition($page, 'window.__heldUploads.length > 0');
    $page->keys('@posting-streak-popover', 'Escape');
    waitForGone($page, '@posting-streak-popover');
    $page->script('window.__releaseUploads()');
    letPageRun($page, 3000);

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@flash-error-toast')
        ->assertNoJavaScriptErrors();
});

test('the badge hides while the sidebar is collapsed', function () {
    postingStreakBrowserWeeks($this->instagram, [0]);

    $page = visit(route('app.insights'))->resize(1280, 900);
    waitFor($page, '@posting-streak-badge');
    $page->click('@sidebar-footer-toggle');
    waitForState($page, '[data-slot="sidebar"][data-state]', "element.dataset.state === 'collapsed'");
    waitForState($page, '@posting-streak-badge', 'element.getBoundingClientRect().width === 0');

    $page->assertNoJavaScriptErrors();
});
