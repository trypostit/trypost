<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function waitForWorkspaceInsightsTestId(mixed $page, string $testId): void
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

function workspaceInsightsTestIdSelector(string $testId): string
{
    return json_encode("[data-testid=\"{$testId}\"]");
}

function waitForWorkspaceInsightsTestIdGone(mixed $page, string $testId): void
{
    $selector = workspaceInsightsTestIdSelector($testId);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector({$selector})) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function waitForWorkspaceInsightsQueryParameter(mixed $page, string $name, string $value): void
{
    $name = json_encode($name);
    $value = json_encode($value);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (new URLSearchParams(location.search).get({$name}) === {$value}) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function clickWorkspaceInsightsTestIdUntilDialogOpens(mixed $page, string $testId): void
{
    $selector = workspaceInsightsTestIdSelector($testId);

    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[role="dialog"]')) return;
                if (attempt % 10 === 0) document.querySelector({$selector}).click();
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return list<string>
 */
function workspaceInsightsSectionTitles(mixed $page, ?string $withinTestId = null): array
{
    $scope = $withinTestId === null ? '' : "[data-testid=\"{$withinTestId}\"] ";
    $selector = json_encode("{$scope}[data-testid=\"analytics-section-title\"]");

    return $page->script("Array.from(document.querySelectorAll({$selector})).map((heading) => heading.textContent.trim())");
}

function workspaceInsightsInnerText(mixed $page, string $testId): ?string
{
    $selector = workspaceInsightsTestIdSelector($testId);

    return $page->script("document.querySelector({$selector})?.innerText ?? null");
}

/**
 * @return array{left: float, right: float, top: float, bottom: float, width: float, height: float}|null
 */
function workspaceInsightsRect(mixed $page, string $testId): ?array
{
    $selector = workspaceInsightsTestIdSelector($testId);

    return $page->script(<<<JS
        (() => {
            const element = document.querySelector({$selector});
            if (!element) return null;
            const { left, right, top, bottom, width, height } = element.getBoundingClientRect();

            return { left, right, top, bottom, width, height };
        })();
    JS);
}

function workspaceInsightsHasNoHorizontalOverflow(mixed $page): bool
{
    return $page->script('document.documentElement.scrollWidth <= window.innerWidth + 2');
}

function workspaceInsightsScrollerScrolls(mixed $page): bool
{
    return $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');

            return ['auto', 'scroll'].includes(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight;
        })();
    JS);
}

function workspaceInsightsSpaceBelowLastSection(mixed $page): float
{
    return $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            const lastSection = Array.from(document.querySelectorAll('[data-testid="analytics-section"]')).at(-1);

            return scroller.scrollHeight - (lastSection.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + scroller.scrollTop);
        })();
    JS);
}

function workspaceInsightsHeaderSticksWhenScrolled(mixed $page): bool
{
    return $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            scroller.scrollTop = scroller.scrollHeight;
            const header = document.querySelector('[data-testid="analytics-page-header"]');
            const toolbar = document.querySelector('[data-testid="analytics-toolbar"]');
            const top = scroller.getBoundingClientRect().top;
            const stuck = scroller.scrollTop > 0 && Math.abs(header.getBoundingClientRect().top - top) <= 1 && toolbar.getBoundingClientRect().bottom > top;
            scroller.scrollTop = 0;

            return stuck;
        })();
    JS);
}

/**
 * @return array{before: int, during: list<int>, after: int}
 */
function workspaceInsightsChartWidthsAroundSidebarToggle(mixed $page): array
{
    return $page->script(<<<'JS'
        (async () => {
            const chart = () => document.querySelector('[data-testid="accounts-unovis-bar-chart"] [data-vis-xy-container]');
            const before = chart().getBoundingClientRect().width;
            document.querySelector('[data-testid="sidebar-footer-toggle"]').click();
            const during = [];
            const started = performance.now();
            while (performance.now() - started < 180) {
                await new Promise((resolve) => requestAnimationFrame(resolve));
                during.push(Math.round(chart().getBoundingClientRect().width));
            }
            await new Promise((resolve) => setTimeout(resolve, 600));
            return { before: Math.round(before), during, after: Math.round(chart().getBoundingClientRect().width) };
        })();
    JS);
}

test('workspace dashboard separates accounts and switches follower and post chart modes', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    foreach ([['first', 120], ['second', 30]] as [$username, $followers]) {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Instagram,
            'username' => $username,
        ]);
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'account_username' => $username,
            'date' => now()->subDay()->toDateString(),
            'followers_count' => $followers,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'account_username' => $username,
            'provider_published_at' => now()->subDays(10)->setTime(10, 0),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'reactions_count' => $followers,
            'comments_count' => 1,
        ]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-summary-followers');

    $page->assertVisible('@analytics-summary-followers');

    expect(workspaceInsightsSectionTitles($page))->toBe(['Summary', 'Top 5 Posts', 'Performance', 'Followers', 'Posts']);

    $page->assertSee('@first')
        ->assertSee('@second')
        ->assertSee('Top 5 Posts')
        ->assertPresent('@analytics-top-post')
        ->assertPresent('@header-icon')
        ->assertPresent('@insights-range-caption')
        ->assertSee('Performance')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->hover('[data-testid="analytics-account-bar"] >> nth=0')
        ->assertPresent('@analytics-tooltip-avatar')
        ->click('@followers-line')
        ->assertVisible('@followers-unovis-chart')
        ->click('@followers-growth')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->assertAttribute('@posts-bar', 'aria-pressed', 'true')
        ->assertAttribute('@posts-stacked', 'aria-pressed', 'false')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->click('@posts-stacked')
        ->assertVisible('@posts-unovis-chart')
        ->hover('[data-testid="analytics-post-bar"] >> nth=0')
        ->assertPresent('@analytics-tooltip-avatar')
        ->click('@top-comments');

    expect(workspaceInsightsScrollerScrolls($page))->toBeTrue()
        ->and(workspaceInsightsSpaceBelowLastSection($page))->toBeGreaterThanOrEqual(24)
        ->and(workspaceInsightsHeaderSticksWhenScrolled($page))->toBeTrue();

    $page->resize(375, 812);

    expect(workspaceInsightsRect($page, 'analytics-page-header')['top'] ?? null)->toBeGreaterThan(workspaceInsightsRect($page, 'app-sidebar-trigger')['bottom'] ?? null)
        ->and(workspaceInsightsHasNoHorizontalOverflow($page))->toBeTrue();

    $page->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('workspace dashboard explains the empty state without inventing follower totals', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-empty-state');

    $page->assertVisible('@analytics-empty-state')
        ->assertPresent('@app-layout-content')
        ->assertPresent('@analytics-toolbar')
        ->assertMissing('@analytics-summary')
        ->assertSee('Turn your posts into insights')
        ->assertSee('Understand your performance and spot opportunities without the guesswork.')
        ->assertCount('@analytics-empty-tile', 7)
        ->assertPresent('@analytics-empty-summary')
        ->assertPresent('@analytics-empty-top-posts')
        ->assertPresent('@analytics-empty-metrics')
        ->assertCount('[data-testid="analytics-empty-top-posts"] > *', 5);

    expect(workspaceInsightsSectionTitles($page, 'analytics-empty-preview'))->toBe(['Summary', 'Top 5 Posts', 'Metrics'])
        ->and(trim(preg_replace('/\s+/u', ' ', (string) workspaceInsightsInnerText($page, 'analytics-empty-summary'))))->toBe('Likes Comments Impressions Engagement rate');

    $page->click('@analytics-empty-connect');

    waitForWorkspaceInsightsTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')
        ->resize(375, 812);

    expect(workspaceInsightsHasNoHorizontalOverflow($page))->toBeTrue();

    $page->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the empty insights hero explains pending data once a channel is connected', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-empty-state');

    $page->assertVisible('@analytics-empty-state')
        ->assertSee('Your analytics history is being prepared')
        ->assertDontSee('Turn your posts into insights')
        ->assertMissing('@analytics-empty-connect')
        ->assertPresent('@analytics-empty-metrics')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('the custom preset opens the calendar with quick ranges that apply a custom range', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $latestDay = now()->subMonths(3)->startOfDay();
    $firstDay = $latestDay->subMonths(6);

    foreach ([$firstDay, $latestDay] as $date) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => $date->toDateString(),
            'followers_count' => 100,
        ]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights', ['start' => $firstDay->toDateString(), 'end' => $firstDay->addMonth()->toDateString()]));
    waitForWorkspaceInsightsTestId($page, 'insights-range-custom');

    $page->assertMissing('@date-range-picker-trigger')
        ->assertAttribute('@insights-range-custom', 'aria-pressed', 'true')
        ->assertAttribute('[data-testid="insights-range-presets"] [role=group]', 'aria-label', 'Período')
        ->assertPresent('@insights-range-custom');
    clickWorkspaceInsightsTestIdUntilDialogOpens($page, 'insights-range-custom');

    $page->assertPresent('[role=dialog]')
        ->assertSeeIn('@date-range-preset-last_7_days', __('common.date_range_picker.last_7_days', [], 'pt-BR'))
        ->click('@date-range-preset-last_7_days');
    waitForWorkspaceInsightsQueryParameter($page, 'start', $latestDay->subDays(6)->toDateString());

    $page->assertQueryStringHas('range', 'custom')
        ->assertQueryStringHas('start', $latestDay->subDays(6)->toDateString())
        ->assertQueryStringHas('end', $latestDay->toDateString())
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('a range preset on the workspace dashboard reloads with that range', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => now('UTC')->toDateString(),
        'followers_count' => 100,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'insights-range-7d');

    $page->click('@insights-range-7d');
    waitForWorkspaceInsightsQueryParameter($page, 'range', '7d');

    $page->assertQueryStringHas('range', '7d')
        ->assertAttribute('@insights-range-7d', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('dashboard refreshes when the first analytics snapshot arrives after opening the page', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-empty-state');
    $page->assertVisible('@analytics-empty-state');

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => now('UTC')->toDateString(),
        'followers_count' => 123,
    ]);

    waitForWorkspaceInsightsTestId($page, 'analytics-summary-followers');

    $page->assertVisible('@analytics-summary-followers')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('import progress disappears after the queued backfill finishes without navigating away', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Running,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-import-coverage');
    $page->assertVisible('@analytics-import-coverage');

    $state->update(['status' => SyncStatus::Complete]);
    waitForWorkspaceInsightsTestIdGone($page, 'analytics-import-coverage');

    $page->assertMissing('@analytics-import-coverage')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('follower chart localizes tooltip values and names accounts without usernames', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::InstagramFacebook,
        'username' => null,
        'display_name' => null,
    ]);

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::InstagramFacebook,
        'network' => Platform::InstagramFacebook->network(),
        'platform_user_id' => $account->platform_user_id,
        'account_display_name' => null,
        'account_username' => null,
        'date' => now('UTC')->subDays(3)->toDateString(),
        'followers_count' => 1234,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'));

    $page->assertPresent('@accounts-unovis-bar-chart')
        ->assertSee('Instagram')
        ->assertDontSee('instagram-facebook')
        ->hover('[data-testid="analytics-account-bar"] >> nth=0');

    expect(workspaceInsightsInnerText($page, 'analytics-chart-tooltip'))->toContain('1.234');

    $page->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('workspace dashboard abbreviates large percentage changes in the user locale', function (Locale $locale, string $expectedChange) {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['locale' => $locale]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
    ]);

    $rangeEnd = now()->subDays(20)->startOfDay();
    $rangeStart = $rangeEnd->subDays(11);

    foreach ([[$rangeStart->subDays(11)->setTime(10, 0), 1], [$rangeEnd->setTime(10, 0), 1866]] as [$publishedAt, $reactions]) {
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'provider_published_at' => $publishedAt,
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'reactions_count' => $reactions,
        ]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights', ['start' => $rangeStart->toDateString(), 'end' => $rangeEnd->toDateString()]));
    waitForWorkspaceInsightsTestId($page, 'analytics-summary-reactions-change');

    expect(workspaceInsightsInnerText($page, 'analytics-summary-reactions-change'))->toContain($expectedChange);

    $page->assertDontSee('+186500%')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with([
    'Portuguese' => [Locale::PortugueseBrazil, "+186,5\u{00A0}mil%"],
]);

test('the channel filter offers to connect a channel when the workspace has none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, 'analytics-channel-filter');

    $page->click('@analytics-channel-filter');
    waitForWorkspaceInsightsTestId($page, 'analytics-channel-connect');

    $page->assertSeeIn('@analytics-channel-connect', __('channels.connect'))
        ->click('@analytics-channel-connect');
    waitForWorkspaceInsightsTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('on a phone the workspace insights range presets collapse into a dropdown', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => now('UTC')->toDateString(),
        'followers_count' => 100,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'))->resize(390, 844);
    waitForWorkspaceInsightsTestId($page, 'insights-range-mobile-trigger');

    expect(workspaceInsightsRect($page, 'insights-range-7d')['height'] ?? null)->toBe(0);

    $page->assertSeeIn('@insights-range-mobile-trigger', '30 Days')
        ->click('@insights-range-mobile-trigger');
    waitForWorkspaceInsightsTestId($page, 'insights-range-mobile-30d-check');

    $page->assertPresent('@insights-range-mobile-30d-check')
        ->assertMissing('@insights-range-mobile-7d-check')
        ->click('@insights-range-mobile-7d');
    waitForWorkspaceInsightsQueryParameter($page, 'range', '7d');

    $page->assertQueryStringHas('range', '7d')
        ->assertSeeIn('@insights-range-mobile-trigger', '7 Days')
        ->assertNoJavaScriptErrors();
});

test('charts keep their width while the sidebar animates and resize once it settles', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'username' => 'first',
    ]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'account_username' => 'first',
        'date' => now()->subDay()->toDateString(),
        'followers_count' => 120,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'))->resize(1440, 900);
    waitForWorkspaceInsightsTestId($page, 'accounts-unovis-bar-chart');
    waitForWorkspaceInsightsTestId($page, 'sidebar-footer-toggle');

    $widths = workspaceInsightsChartWidthsAroundSidebarToggle($page);

    expect(array_unique($widths['during']))->toBe([$widths['before']])
        ->and($widths['after'])->toBeGreaterThan($widths['before']);
    $page->assertNoJavaScriptErrors();
});

test('top posts tag each post type like the channel table', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $publications = [];

    foreach ([
        'reel' => [Platform::Instagram, PublicationContentType::Reel],
        'image' => [Platform::Instagram, PublicationContentType::Image],
        'tiktok' => [Platform::TikTok, PublicationContentType::Video],
    ] as $key => [$platform, $contentType]) {
        $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform]);
        $publications[$key] = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => $platform,
            'network' => $platform->network(),
            'content_type' => $contentType,
            'provider_published_at' => now()->subDays(3)->setTime(10, 0),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publications[$key]->id, 'reactions_count' => 10]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, "analytics-top-post-type-{$publications['reel']->id}");

    $page->assertScript("document.querySelector('[data-testid=\"analytics-top-post-type-{$publications['reel']->id}-icon\"]').classList.contains('tabler-icon-circle-play')", true)
        ->assertMissing("@analytics-top-post-type-{$publications['image']->id}")
        ->assertMissing("@analytics-top-post-type-{$publications['tiktok']->id}")
        ->assertNoJavaScriptErrors();
});

test('top posts show the post media from our bucket, a video by its cover frame', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $publications = [];

    foreach ([
        'video' => ['id' => 'video', 'path' => 'medias/reel.mp4', 'url' => 'https://cdn.trypost.test/medias/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'],
        'image' => ['id' => 'image', 'path' => 'medias/a.jpg', 'url' => 'http://trypost.test/storage/medias/a.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'],
    ] as $key => $media) {
        $post = Post::factory()->forAccount($account)->published()->create(['media' => [$media]]);
        $publications[$key] = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'post_id' => $post->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'preview_metadata' => ['thumbnail_url' => 'https://scontent.cdninstagram.com/expired.jpg?oe=6ACC450F'],
            'provider_published_at' => now()->subDays(3)->setTime(10, 0),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publications[$key]->id, 'reactions_count' => 10]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights'));
    waitForWorkspaceInsightsTestId($page, "analytics-top-post-video-{$publications['video']->id}");

    $page->assertAttribute("@analytics-top-post-video-{$publications['video']->id}", 'src', 'https://cdn.trypost.test/medias/reel.mp4#t=0.1')
        ->assertScript("Array.from(document.querySelectorAll('[data-testid=\"analytics-top-post\"] img')).map((image) => image.getAttribute('src')).includes('http://trypost.test/storage/medias/a.jpg')", true)
        ->assertNoJavaScriptErrors();
});
