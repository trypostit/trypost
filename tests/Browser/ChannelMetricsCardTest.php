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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function waitForMetricsCardTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 600; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForMetricsCardScript(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 400; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @param  array<string, mixed>  $metrics
 */
function metricsCardPublication(SocialAccount $account, CarbonImmutable $publishedAt, array $metrics): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'provider_published_at' => $publishedAt,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $publishedAt->toDateString(),
        ...$metrics,
    ]);

    return $publication;
}

function metricsCardFollowers(SocialAccount $account, CarbonImmutable $date, int $followers): void
{
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => $date->toDateString(),
        'followers_count' => $followers,
    ]);
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
    ]);
    $today = CarbonImmutable::today('UTC');

    foreach (range(0, 59) as $daysAgo) {
        metricsCardFollowers($this->instagram, $today->subDays($daysAgo), 3000 + (60 - $daysAgo) * 5);
    }

    foreach ([2, 5, 9, 14, 20, 26] as $index => $daysAgo) {
        metricsCardPublication($this->instagram, $today->subDays($daysAgo)->setTime(10, 0), [
            'reactions_count' => 10 + $index,
            'views_count' => 300 + $index * 20,
            'reach_count' => 200 + $index * 10,
            'saves_count' => 2,
            'watch_time_milliseconds' => 120000,
            'metrics' => ['profile_visits' => ['value' => 3, 'unit' => 'count', 'availability' => 'available']],
        ]);
    }

    metricsCardPublication($this->instagram, $today->subDays(40)->setTime(10, 0), [
        'reactions_count' => 4,
        'views_count' => 90,
        'reach_count' => 80,
        'metrics' => ['profile_visits' => ['value' => 1, 'unit' => 'count', 'availability' => 'available']],
    ]);

    $this->actingAs($this->user);
});

test('the metrics card loads after the page and opens on content impact', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-metrics-chart');

    $page->assertMissing('@insights-metrics-skeleton')
        ->assertAttribute('@insights-preset-content_impact', 'aria-pressed', 'true')
        ->assertVisible('@insights-preset-audience_growth')
        ->assertVisible('@insights-preset-visibility')
        ->assertVisible('@insights-preset-follower_growth_rate')
        ->assertSeeIn('@insights-metrics-legend-posts-value', '6')
        ->assertVisible('@insights-metrics-legend-followers')
        ->assertPresent('@insights-metrics-table')
        ->assertScript('document.querySelectorAll("[data-testid=insights-metrics-bar]").length > 0', true)
        ->assertScript('document.querySelectorAll("[data-testid=insights-metrics-chart] svg").length > 0', true)
        ->assertMissing('@followers-bar')
        ->assertMissing('@posts-chart-bar');

    waitForMetricsCardTestId($page, 'insights-posts');

    $page->assertScript('Boolean(document.querySelector("[data-testid=insights-posts]").compareDocumentPosition(document.querySelector("[data-testid=insights-metrics]")) & Node.DOCUMENT_POSITION_FOLLOWING)', true)
        ->assertScript(<<<'JS'
            [...document.querySelectorAll('[data-testid="insights-metrics-controls"] button, [data-testid="insights-metrics-modes"] button')]
                .map((button) => [button.classList.contains('h-7'), Math.round(button.getBoundingClientRect().height)])
                .every(([small, height]) => small && height === 28)
        JS, true)
        ->assertScript('document.querySelectorAll("[data-testid=insights-metrics-controls] button, [data-testid=insights-metrics-modes] button").length', 9)
        ->assertNoJavaScriptErrors();
});

test('the card shows a skeleton while the series reloads for a new range', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-metrics-chart');

    $page->script(<<<'JS'
        window.__sawMetricsSkeleton = false;
        new MutationObserver(() => {
            if (document.querySelector('[data-testid="insights-metrics-skeleton"]')) {
                window.__sawMetricsSkeleton = true;
            }
        }).observe(document.body, { childList: true, subtree: true });
    JS);
    $page->click('@insights-range-7d');
    waitForMetricsCardScript($page, 'new URLSearchParams(location.search).get("range") === "7d" && window.__sawMetricsSkeleton && document.querySelector("[data-testid=insights-metrics-chart]")');

    $page->assertScript('window.__sawMetricsSkeleton', true)
        ->assertVisible('@insights-metrics-chart')
        ->assertNoJavaScriptErrors();
});

test('the metric menu lists the channel metrics and switches the chart to one metric', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-metric-menu');

    $page->click('@insights-metric-menu');
    waitForMetricsCardTestId($page, 'insights-metric-option-reach');
    $page->assertScript('[...document.querySelectorAll("[data-testid^=insights-metric-option-]:not([data-testid$=-check])")].map((item) => item.dataset.testid.replace("insights-metric-option-", "")).join(",")', 'followers,reach,profile_visits,posts,net_followers')
        ->assertSeeIn('@insights-metric-option-profile_visits', 'Profile Views')
        ->assertSeeIn('@insights-metric-option-net_followers', 'Net New Followers')
        ->assertPresent('@insights-metric-option-posts-check')
        ->assertMissing('@insights-metric-option-views')
        ->assertMissing('@insights-metric-option-impressions')
        ->click('@insights-metric-option-reach');
    waitForMetricsCardTestId($page, 'insights-metrics-legend-reach');

    $page->assertAttribute('@insights-metric-primary', 'aria-pressed', 'true')
        ->assertAttribute('@insights-preset-content_impact', 'aria-pressed', 'false')
        ->assertSeeIn('@insights-metric-primary', 'Reach')
        ->assertMissing('@insights-metrics-legend-posts')
        ->assertSeeIn('@insights-metrics-legend-reach', 'Reach')
        ->assertSeeIn('@insights-metrics-legend-reach-value', '1.4K')
        ->assertVisible('@insights-metrics-post-totals-note')
        ->click('@insights-metric-menu');
    waitForMetricsCardTestId($page, 'insights-metric-option-reach-check');

    $page->assertMissing('@insights-metric-option-posts-check')
        ->assertNoJavaScriptErrors();
});

test('the follower growth rate preset draws the last twelve months and ignores the filters', function () {
    $page = visit(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d']));
    waitForMetricsCardTestId($page, 'insights-preset-follower_growth_rate');

    $page->click('@insights-preset-follower_growth_rate');
    waitForMetricsCardTestId($page, 'insights-metrics-legend-growth_rate');

    $page->assertAttribute('@insights-preset-follower_growth_rate', 'aria-pressed', 'true')
        ->assertSeeIn('@insights-metrics-legend-growth_rate', 'Growth Rate')
        ->assertScript('document.querySelector("[data-testid=insights-metrics-legend-growth_rate-value]").textContent.trim().endsWith("%")', true)
        ->assertSeeIn('@insights-metrics-filters-note', "Date and label filters don't apply to this chart")
        ->assertMissing('@insights-metrics-modes')
        ->assertVisible('@insights-metrics-chart')
        ->assertScript('document.querySelectorAll("[data-testid=insights-metrics-table] tbody tr").length', 12)
        ->assertScript('document.querySelector("[data-testid=insights-metrics-insight]").dataset.insight.startsWith("growth_rate.")', true)
        ->click('@insights-preset-audience_growth');
    waitForMetricsCardTestId($page, 'insights-metrics-modes');

    $page->assertMissing('@insights-metrics-filters-note')
        ->assertNoJavaScriptErrors();
});

test('presets swap the chart series and the selected preset and period survive a filter change', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-preset-audience_growth');

    $page->click('@insights-preset-audience_growth')
        ->click('@insights-metrics-mode-both');
    waitForMetricsCardTestId($page, 'insights-metrics-legend-net_followers-previous');

    $page->click('@insights-post-type-filter');
    waitForMetricsCardTestId($page, 'insights-post-type-option-instagram_reel');
    $page->click('@insights-post-type-option-instagram_reel');
    waitForMetricsCardScript($page, 'new URLSearchParams(location.search).getAll("types[]").includes("instagram_reel") && document.querySelector("[data-testid=insights-metrics-chart]") && document.querySelector("[data-testid=insights-preset-audience_growth]")?.getAttribute("aria-pressed") === "true" && document.querySelector("[data-testid=insights-metrics-mode-both]")?.getAttribute("aria-pressed") === "true" && document.querySelector("[data-testid=insights-metrics-legend-net_followers-previous]")');

    $page->assertScript('decodeURIComponent(location.search).includes("instagram_reel")', true)
        ->assertAttribute('@insights-preset-audience_growth', 'aria-pressed', 'true')
        ->assertAttribute('@insights-metrics-mode-both', 'aria-pressed', 'true')
        ->assertVisible('@insights-metrics-legend-net_followers-previous')
        ->assertVisible('@insights-metrics-legend-followers')
        ->assertMissing('@insights-metrics-legend-posts');

    $page->click('@insights-preset-visibility');
    waitForMetricsCardTestId($page, 'insights-metrics-legend-profile_visits');
    $page->assertVisible('@insights-metrics-legend-followers')
        ->assertNoJavaScriptErrors();
});

test('a one-day range still draws the posts of that day', function () {
    $today = CarbonImmutable::today('UTC');
    $page = visit(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => $today->subDays(2)->toDateString(), 'end' => $today->subDays(2)->toDateString()]));
    waitForMetricsCardTestId($page, 'insights-metrics-legend-posts-value');

    $page->assertSeeIn('@insights-metrics-legend-posts-value', '1')
        ->assertVisible('@insights-metrics-chart')
        ->assertMissing('@insights-metrics-no-history')
        ->assertNoJavaScriptErrors();
});

test('the mode toggle shows the comparison period and both periods', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-metrics-mode-both');

    $page->assertMissing('@insights-metrics-legend-posts-previous')
        ->assertSeeIn('@insights-metrics-mode-previous', 'Comparison')
        ->click('@insights-metrics-mode-both');
    waitForMetricsCardTestId($page, 'insights-metrics-legend-posts-previous');

    $page->assertSeeIn('@insights-metrics-legend-posts-previous', '1')
        ->assertSeeIn('@insights-metrics-legend-posts-value', '6')
        ->assertAttribute('@insights-metrics-mode-both', 'aria-pressed', 'true')
        ->assertScript('document.querySelectorAll("[data-testid=insights-metrics-bar]").length > 0', true)
        ->click('@insights-metrics-mode-previous');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-legend-posts-value]")?.textContent.trim() === "1"');

    $page->assertSeeIn('@insights-metrics-legend-posts-value', '1')
        ->assertMissing('@insights-metrics-legend-posts-previous')
        ->assertScript('document.querySelector("[data-testid=insights-metrics-chart]").textContent.includes("Posts (comparison)")', true)
        ->assertNoJavaScriptErrors();
});

test('the insight banner reads the selected chart against the previous period', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-metrics-insight');

    $page->assertAttribute('@insights-metrics-insight', 'data-insight', 'content_impact.down')
        ->assertSeeIn('@insights-metrics-insight', 'Your content impact dropped this period.')
        ->click('@insights-metric-primary');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-insight]")?.dataset.insight === "posts.up"');

    $page->assertSeeIn('@insights-metrics-insight', 'You posted more than last period.')
        ->click('@insights-preset-audience_growth');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-insight]")?.dataset.insight === "audience.up"');

    $page->assertSeeIn('@insights-metrics-insight', 'Your audience grew this period.')
        ->assertMissing('@insights-metrics-post-totals-note')
        ->click('@insights-metrics-mode-previous');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-mode-previous]")?.getAttribute("aria-pressed") === "true"');

    $page->assertAttribute('@insights-metrics-insight', 'data-insight', 'audience.up')
        ->click('@insights-metrics-mode-both');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-insight]")?.dataset.insight.startsWith("audience_compared")');

    $page->assertSeeIn('@insights-metrics-insight', 'Your audience grew')
        ->click('@insights-preset-visibility');
    waitForMetricsCardScript($page, 'document.querySelector("[data-testid=insights-metrics-insight]")?.dataset.insight === "visibility.up"');

    $page->assertSeeIn('@insights-metrics-insight', 'More people discovered you than last period.')
        ->assertNoJavaScriptErrors();
});

test('on a phone the presets wrap and the mode toggle sits under the legend', function () {
    $page = visit(route('app.channels.insights', $this->instagram))->resize(390, 844);
    waitForMetricsCardTestId($page, 'insights-metrics-chart');

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertScript(<<<'JS'
            (() => {
                const card = document.querySelector('[data-testid="insights-metrics"]').getBoundingClientRect();
                return [...document.querySelectorAll('[data-testid="insights-metrics-controls"] button')]
                    .every((button) => button.getBoundingClientRect().right <= card.right + 1);
            })()
        JS, true)
        ->assertScript(<<<'JS'
            (() => {
                const legend = document.querySelector('[data-testid="insights-metrics-legend"]').getBoundingClientRect();
                const toggle = document.querySelector('[data-testid="insights-metrics-mode-both"]').getBoundingClientRect();
                return toggle.top >= legend.bottom;
            })()
        JS, true)
        ->assertNoJavaScriptErrors();
});

test('the column chooser hides and adds columns and remembers the choice', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForMetricsCardTestId($page, 'insights-columns-trigger');

    $page->assertVisible('@insights-sort-views')
        ->assertMissing('@insights-column-head-watch_time_minutes')
        ->click('@insights-columns-trigger');
    waitForMetricsCardTestId($page, 'insights-column-views');

    $page->assertMissing('@insights-column-impressions')
        ->click('@insights-column-views');
    waitForMetricsCardScript($page, '!document.querySelector("[data-testid=insights-sort-views]")');
    $page->click('@insights-column-watch_time_minutes');
    waitForMetricsCardTestId($page, 'insights-column-head-watch_time_minutes');

    $page->assertMissing('@insights-sort-views')
        ->assertVisible('@insights-column-head-watch_time_minutes')
        ->assertVisible('@insights-column-views');

    $page->refresh();
    waitForMetricsCardTestId($page, 'insights-column-head-watch_time_minutes');

    $page->assertMissing('@insights-sort-views')
        ->assertVisible('@insights-sort-reactions')
        ->assertNoJavaScriptErrors();
});
