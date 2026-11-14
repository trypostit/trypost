<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

/** @return array{0: User, 1: list<SocialAccount>} */
function insightsChannelFilterFixture(): array
{
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = [];

    foreach ([['alpha', 120], ['bravo', 30]] as [$username, $followers]) {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Instagram,
            'username' => $username,
        ]);

        foreach ([[now('UTC')->subDays(5), $followers - 10], [now('UTC')->subDays(2), $followers]] as [$date, $count]) {
            AnalyticsAccountDailySnapshot::factory()->create([
                'workspace_id' => $workspace->id,
                'social_account_id' => $account->id,
                'social_account_key' => $account->id,
                'platform' => Platform::Instagram,
                'network' => Platform::Instagram->network(),
                'platform_user_id' => $account->platform_user_id,
                'account_username' => $username,
                'date' => $date->toDateString(),
                'followers_count' => $count,
            ]);
        }

        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'account_username' => $username,
            'provider_published_at' => now('UTC')->subDays(3),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'reactions_count' => $followers,
            'comments_count' => 1,
        ]);
        $accounts[] = $account;
    }

    return [$user, $accounts];
}

test('the channel filter scopes the dashboard and keeps the channel in the url', function () {
    [$user, [$alpha]] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-channel-filter');
    $page->assertScript('document.querySelectorAll("[data-testid=analytics-performance-caption]").length', 1)
        ->click('@analytics-channel-filter');
    waitFor($page, "@analytics-channel-checkbox-{$alpha->id}");
    $page->click("@analytics-channel-checkbox-{$alpha->id}");
    waitForCondition($page, "[...new URLSearchParams(location.search).keys()].filter((key) => key.startsWith('channels')).length === 1");
    waitFor($page, '@insights-card-followers');

    $page->assertScript('[...new URLSearchParams(location.search)].filter(([key]) => key.startsWith("channels")).map(([, value]) => value)', [$alpha->id])
        ->assertScript('document.querySelector("[data-testid=insights-card-followers]")?.innerText.includes("120")', true)
        ->assertScript('document.body.innerText.includes("@bravo")', false)
        ->assertNoJavaScriptErrors();

    $page->click('@insights-range-7d');
    waitForQueryParameter($page, 'range', '7d');

    $page->assertScript('[...new URLSearchParams(location.search)].filter(([key]) => key.startsWith("channels")).map(([, value]) => value)', [$alpha->id])
        ->assertNoJavaScriptErrors();
});

test('the follower bar tooltip shows the verified mark of an X channel', function () {
    [$user, $accounts] = insightsChannelFilterFixture();
    $accounts[0]->update(['platform' => Platform::X, 'meta' => ['x_verified_type' => 'blue']]);
    AnalyticsAccountDailySnapshot::query()->where('social_account_id', $accounts[0]->id)->update(['platform' => Platform::X, 'network' => Platform::X->network()]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-account-bar');
    $page->hover('[data-testid="analytics-account-bar"] >> nth=1');
    waitFor($page, '@analytics-chart-tooltip [data-verified="blue"]');

    $page->assertPresent('[data-testid="analytics-chart-tooltip"] [data-verified="blue"]')
        ->assertNoJavaScriptErrors();
});

test('the followers chart prints each channel’s number next to its bar or at the end of its line', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-account-value');

    $values = 'Array.from(document.querySelector("[data-testid=accounts-unovis-bar-chart]").querySelectorAll("[data-testid=analytics-account-value]"), (value) => value.innerText).sort().join(",")';
    $aligned = '(() => { const chart = document.querySelector("[data-testid=accounts-unovis-bar-chart]"); const edges = Array.from(chart.querySelectorAll("[data-testid=analytics-account-value]"), (value) => Math.round(value.getBoundingClientRect().right)); const barStart = Math.min(...Array.from(chart.querySelectorAll("[data-testid=analytics-account-bar]"), (bar) => bar.getBoundingClientRect().left)); return new Set(edges).size === 1 && edges[0] < barStart; })()';

    $page->assertScript($values, '120,30')
        ->assertScript('document.querySelector("[data-testid=analytics-axis-channel]").closest("[aria-hidden=true]") === null', true)
        ->assertSeeIn('@analytics-followers-change', '+20')
        ->assertScript($aligned, true);

    $page->click('@followers-growth');
    waitForText($page, '@analytics-account-value', '+10');

    $page->assertScript($values, '+10,+10');

    $page->click('@followers-line');
    waitForText($page, '@analytics-line-end-value', '120');

    $page->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-line-end-value]"), (value) => value.innerText).sort().join(",")', '120,30')
        ->assertScript('(() => { const chart = document.querySelector("[data-testid=followers-unovis-chart]").getBoundingClientRect(); return Array.from(document.querySelectorAll("[data-testid=followers-line-end]")).every((label) => label.getBoundingClientRect().left >= chart.left + chart.width / 2); })()', true);

    $page->hover('[data-testid="followers-line-end"] >> nth=0');
    waitForState($page, '@followers-unovis-chart', "element.querySelectorAll('[data-dimmed=true]').length > 0");

    $page->assertScript('document.querySelectorAll("[data-testid=followers-unovis-chart] [data-dimmed=true]").length > 0', true)
        ->assertNoJavaScriptErrors();
});

test('the follower bar tooltip lists where the channel started and the toolbar fits a 390px viewport', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-account-bar');

    $page->assertScript('document.querySelector("[data-testid=followers-bar]")?.getAttribute("aria-pressed")', 'true')
        ->hover('[data-testid="analytics-account-bar"] >> nth=0');
    waitFor($page, '@analytics-tooltip-detail');

    $page->assertScript('document.querySelector("[data-testid=analytics-tooltip-value]")?.innerText', '120')
        ->assertScript('document.querySelectorAll("[data-testid=analytics-tooltip-detail-label]").length', 2)
        ->assertScript('document.querySelector("[data-testid=analytics-tooltip-detail-value]")?.innerText', '110')
        ->assertNoJavaScriptErrors();

    $page->resize(390, 844);
    waitFor($page, '@insights-range-mobile-trigger');

    $page->assertMissing('@insights-range-custom')
        ->assertScript('document.querySelector("[data-testid=insights-range-mobile-trigger]").getBoundingClientRect().right <= window.innerWidth', true)
        ->assertScript('document.querySelector("[data-testid=analytics-channel-filter]").getBoundingClientRect().right <= window.innerWidth', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 2', true)
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-account-name]")).every((name) => name.offsetParent === null)', true)
        ->assertScript('(() => { const grid = document.querySelector("[data-testid=analytics-summary]").getBoundingClientRect(); const last = document.querySelector("[data-testid=analytics-summary] > :last-child").getBoundingClientRect(); return Math.abs(last.width - grid.width) < 2; })()', true)
        ->assertScript('(() => { const value = document.querySelector("[data-testid=analytics-summary-engagement_rate-value]").getBoundingClientRect(); const change = document.querySelector("[data-testid=analytics-summary-engagement_rate-change]")?.getBoundingClientRect(); return !change || Math.abs((change.top + change.bottom) / 2 - (value.top + value.bottom) / 2) < 8; })()', true)
        ->assertScript('(() => { const card = document.querySelector("[data-testid=analytics-summary-posts]"); const change = card.querySelector("[data-testid=analytics-summary-posts-change]"); const value = card.querySelector("[data-testid=analytics-summary-posts-value]"); return !change || change.getBoundingClientRect().top >= value.getBoundingClientRect().bottom - 1; })()', true)
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=accounts-unovis-bar-chart] [data-testid=analytics-account-value]")).every((value) => value.offsetParent !== null)', true)
        ->assertNoJavaScriptErrors();
});

test('a channel without data shows the filtered empty state and history navigation re-syncs the selection', function () {
    [$user, [$alpha]] = insightsChannelFilterFixture();
    $silent = SocialAccount::factory()->create([
        'workspace_id' => $alpha->workspace_id,
        'platform' => Platform::Instagram,
        'username' => 'silent',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@insights-range-7d');
    $page->click('@insights-range-7d');
    waitForQueryParameter($page, 'range', '7d');
    waitFor($page, '@analytics-channel-filter');
    $page->click('@analytics-channel-filter');
    waitFor($page, "@analytics-channel-checkbox-{$silent->id}");
    $page->click("@analytics-channel-checkbox-{$silent->id}");
    waitFor($page, '@analytics-filtered-empty-state');

    $page->assertPresent('@analytics-filtered-empty-state')
        ->assertMissing('@analytics-empty-state')
        ->assertScript('document.querySelector("[data-testid=analytics-channel-count]")?.innerText', '1')
        ->assertScript('new URLSearchParams(location.search).get("range")', '7d');

    $page->script('history.back()');
    waitForCondition($page, "location.pathname.endsWith('/insights') && !location.search.includes('range=7d')");
    waitFor($page, '@analytics-summary-followers');

    $page->assertScript('location.pathname.endsWith("/insights") && !location.search.includes("channels")', true)
        ->assertPresent('@analytics-summary-followers')
        ->assertMissing('@analytics-channel-count')
        ->assertMissing('@analytics-filtered-empty-state')
        ->assertNoJavaScriptErrors();
});

test('a channel that lost followers reads as a loss in the tooltip, the Growth view and the headline', function () {
    [$user, $accounts] = insightsChannelFilterFixture();
    AnalyticsAccountDailySnapshot::query()
        ->where('social_account_id', $accounts[1]->id)
        ->where('date', now('UTC')->subDays(2)->toDateString())
        ->update(['followers_count' => 5]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-account-bar');

    $page->assertSeeIn('@analytics-followers-change', '-5')
        ->hover('[data-testid="analytics-account-bar"] >> nth=1');
    waitForText($page, '@analytics-tooltip-detail', 'Lost in range');

    $page->assertSeeIn('@analytics-tooltip-detail', '-15 (-75%)')
        ->click('@followers-growth');
    waitForText($page, '@analytics-account-value', '-15');

    $page->assertScript('Array.from(document.querySelector("[data-testid=accounts-unovis-bar-chart]").querySelectorAll("[data-testid=analytics-account-value]"), (value) => value.innerText).sort().join(",")', '+10,-15')
        ->assertNoJavaScriptErrors();
});

test('the posts card heads with every channel’s posts and lists the channels only when stacked', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-posts-total');

    $page->assertSeeIn('@analytics-posts-total', '2')
        ->assertMissing('@analytics-posts-legend')
        ->click('@posts-stacked');
    waitFor($page, '@analytics-posts-legend');

    $page->assertSeeIn('@analytics-posts-total', '2')
        ->click('@posts-bar');
    waitForGone($page, '@analytics-posts-legend');

    $page->assertNoJavaScriptErrors();
});

test('on a phone the channel, label and post type filters open as sheets', function () {
    [$user, $accounts] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'))->resize(390, 844);
    waitFor($page, '@analytics-channel-filter');
    $page->click('@analytics-channel-filter');
    waitFor($page, '@analytics-channel-sheet');

    $page->assertVisible('@analytics-channel-sheet')
        ->assertNoJavaScriptErrors();

    $channel = visit(route('app.channels.insights', $accounts[0]))->resize(390, 844);
    waitFor($channel, '@insights-label-filter');
    $channel->click('@insights-label-filter');
    waitFor($channel, '@insights-label-sheet');

    $channel->assertVisible('@insights-label-sheet')
        ->assertNoJavaScriptErrors();

    $types = visit(route('app.channels.insights', $accounts[0]))->resize(390, 844);
    waitFor($types, '@insights-post-type-filter');
    $types->click('@insights-post-type-filter');
    waitFor($types, '@insights-post-type-sheet');

    $types->assertVisible('@insights-post-type-sheet')
        ->assertNoJavaScriptErrors();
});

test('the followers line axis never prints two ticks alike when the counts are close', function () {
    [$user, $accounts] = insightsChannelFilterFixture();
    AnalyticsAccountDailySnapshot::query()->where('social_account_id', $accounts[1]->id)->delete();
    $accounts[1]->delete();
    AnalyticsAccountDailySnapshot::query()->where('social_account_id', $accounts[0]->id)->orderBy('date')->get()
        ->each(fn (AnalyticsAccountDailySnapshot $snapshot, int $index) => $snapshot->update(['followers_count' => [9990, 10010][$index]]));
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@followers-line');
    $page->click('@followers-line');
    waitFor($page, '@followers-unovis-chart [data-value-tick]');

    $ticks = $page->script('Array.from(document.querySelectorAll(\'[data-testid="followers-unovis-chart"] [data-value-tick]\'), (tick) => tick.textContent.trim())');

    expect(count($ticks))->toBeGreaterThan(2)
        ->and(array_unique($ticks))->toHaveCount(count($ticks));
    $page->assertNoJavaScriptErrors();
});

test('a channel without a follower reading in the period gets no label at the end of the lines', function () {
    [$user, $accounts] = insightsChannelFilterFixture();
    AnalyticsAccountDailySnapshot::query()->where('social_account_id', $accounts[1]->id)->delete();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@followers-line');
    $page->click('@followers-line');
    waitFor($page, '@analytics-line-end-value');

    $page->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-line-end-value]"), (value) => value.innerText).join(",")', '120')
        ->assertNoJavaScriptErrors();
});

test('on a touch screen a tap on a line’s label, which never leaves it, fades no other line', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'))->on()->mobile();
    waitFor($page, '@followers-line');
    $page->click('@followers-line');
    waitFor($page, '@followers-line-end');
    $dimmed = $page->script(<<<'JS'
        (async () => {
            document.querySelector('[data-testid="followers-line-end"]').dispatchEvent(new MouseEvent('mouseenter'));
            const started = performance.now();
            let most = 0;

            while (performance.now() - started < 500) {
                await new Promise((resolve) => requestAnimationFrame(resolve));
                most = Math.max(most, document.querySelectorAll('[data-testid="followers-unovis-chart"] [data-dimmed="true"]').length);
            }

            return most;
        })()
    JS);

    expect($page->script('window.matchMedia("(hover: hover)").matches'))->toBeFalse()
        ->and($dimmed)->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('a follower change of a thousand or more reads short and signed, the same in every language', function () {
    [$user, [$alpha]] = insightsChannelFilterFixture();
    $user->update(['locale' => Locale::PortugueseBrazil]);
    AnalyticsAccountDailySnapshot::query()
        ->where('social_account_id', $alpha->id)
        ->where('followers_count', 120)
        ->update(['followers_count' => 1300]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-followers-change');

    $page->assertSeeIn('@analytics-followers-change', '+1.2K')
        ->assertNoJavaScriptErrors();
});
