<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function waitForInsightsChannelTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

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
    waitForInsightsChannelTestId($page, 'analytics-channel-filter');
    $page->assertScript('document.querySelectorAll("[data-testid=analytics-performance-caption]").length', 1)
        ->click('@analytics-channel-filter');
    waitForInsightsChannelTestId($page, "analytics-channel-checkbox-{$alpha->id}");
    $page->click("@analytics-channel-checkbox-{$alpha->id}");
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ([...new URLSearchParams(location.search).keys()].filter((key) => key.startsWith('channels')).length === 1) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    waitForInsightsChannelTestId($page, 'insights-card-followers');

    $page->assertScript('[...new URLSearchParams(location.search)].filter(([key]) => key.startsWith("channels")).map(([, value]) => value)', [$alpha->id])
        ->assertScript('document.querySelector("[data-testid=insights-card-followers]")?.innerText.includes("120")', true)
        ->assertScript('document.body.innerText.includes("@bravo")', false)
        ->assertNoJavaScriptErrors();

    $page->click('@insights-range-7d');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (new URLSearchParams(location.search).get('range') === '7d') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertScript('[...new URLSearchParams(location.search)].filter(([key]) => key.startsWith("channels")).map(([, value]) => value)', [$alpha->id])
        ->assertNoJavaScriptErrors();
});

test('the follower bar tooltip shows a series swatch and the value', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsChannelTestId($page, 'analytics-account-bar');

    $page->assertScript('document.querySelector("[data-testid=followers-bar]")?.getAttribute("aria-pressed")', 'true')
        ->assertScript('document.querySelectorAll("[data-testid=analytics-legend-swatch]").length >= 2', true)
        ->hover('[data-testid="analytics-account-bar"] >> nth=0');
    waitForInsightsChannelTestId($page, 'analytics-tooltip-swatch');

    $page->assertPresent('@analytics-tooltip-swatch')
        ->assertScript('document.querySelector("[data-testid=analytics-tooltip-value]")?.innerText', '120')
        ->assertScript('document.querySelector("[data-testid=analytics-tooltip-detail]")?.innerText.includes("110")', true)
        ->assertNoJavaScriptErrors();
});

test('the toolbar fits a 390px viewport without clipping the range presets', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'))->resize(390, 844);
    waitForInsightsChannelTestId($page, 'insights-range-custom');

    $page->assertScript('(() => { const group = document.querySelector("[data-testid=insights-range-presets] [role=group]"); return group.scrollWidth <= group.clientWidth + 1; })()', true)
        ->assertScript('document.querySelector("[data-testid=insights-range-custom]").getBoundingClientRect().right <= window.innerWidth', true)
        ->assertScript('document.querySelector("[data-testid=analytics-channel-filter]").getBoundingClientRect().right <= window.innerWidth', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 2', true)
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
    waitForInsightsChannelTestId($page, 'insights-range-7d');
    $page->click('@insights-range-7d');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (new URLSearchParams(location.search).get('range') === '7d') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    waitForInsightsChannelTestId($page, 'analytics-channel-filter');
    $page->click('@analytics-channel-filter');
    waitForInsightsChannelTestId($page, "analytics-channel-checkbox-{$silent->id}");
    $page->click("@analytics-channel-checkbox-{$silent->id}");
    waitForInsightsChannelTestId($page, 'analytics-filtered-empty-state');

    $page->assertPresent('@analytics-filtered-empty-state')
        ->assertMissing('@analytics-empty-state')
        ->assertScript('document.querySelector("[data-testid=analytics-channel-count]")?.innerText', '1')
        ->assertScript('new URLSearchParams(location.search).get("range")', '7d');

    $page->script('history.back()');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (location.pathname.endsWith('/insights') && !location.search.includes('range=7d')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    waitForInsightsChannelTestId($page, 'analytics-summary-followers');

    $page->assertScript('location.pathname.endsWith("/insights") && !location.search.includes("channels")', true)
        ->assertPresent('@analytics-summary-followers')
        ->assertMissing('@analytics-channel-count')
        ->assertMissing('@analytics-filtered-empty-state')
        ->assertNoJavaScriptErrors();
});

test('the sidebar keeps its publish count and channel list on the insights page', function () {
    [$user] = insightsChannelFilterFixture();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsChannelTestId($page, 'sidebar-publish-count');

    expect($page->script("document.querySelector('[data-testid=\"sidebar-publish-count\"]').textContent.trim()"))->toMatch('/^\d+$/')
        ->and($page->script("document.querySelectorAll('[data-testid=\"sidebar-channels-list\"] [data-testid^=\"sidebar-channel-row-\"]').length"))->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});
