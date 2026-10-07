<?php

declare(strict_types=1);

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
            'date' => '2026-09-23',
            'followers_count' => $followers,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'account_username' => $username,
            'provider_published_at' => '2026-09-12 10:00:00',
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

    $page->assertVisible('@analytics-summary-followers')
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-section-title]")).map((heading) => heading.textContent.trim()).join("|")', 'Summary|Top 5 Posts|Performance|Followers|Posts')
        ->assertSee('@first')
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
        ->assertScript('document.querySelector("[data-testid=posts-bar]")?.getAttribute("aria-pressed")', 'true')
        ->assertScript('document.querySelector("[data-testid=posts-stacked]")?.getAttribute("aria-pressed")', 'false')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->click('@posts-stacked')
        ->assertVisible('@posts-unovis-chart')
        ->hover('[data-testid="analytics-post-bar"] >> nth=0')
        ->assertPresent('@analytics-tooltip-avatar')
        ->click('@top-comments')
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); return ["auto", "scroll"].includes(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight; })()', true)
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); const lastSection = Array.from(document.querySelectorAll("[data-testid=analytics-section]")).at(-1); return scroller.scrollHeight - (lastSection.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + scroller.scrollTop) >= 24; })()', true)
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); scroller.scrollTop = scroller.scrollHeight; const header = document.querySelector("[data-testid=analytics-page-header]"); const toolbar = document.querySelector("[data-testid=analytics-toolbar]"); const top = scroller.getBoundingClientRect().top; const stuck = scroller.scrollTop > 0 && Math.abs(header.getBoundingClientRect().top - top) <= 1 && toolbar.getBoundingClientRect().bottom > top; scroller.scrollTop = 0; return stuck; })()', true)
        ->resize(375, 812)
        ->assertScript('document.querySelector("[data-testid=analytics-page-header]")?.getBoundingClientRect().top > document.querySelector("[data-testid=app-sidebar-trigger]")?.getBoundingClientRect().bottom', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 2', true)
        ->assertNoJavaScriptErrors()
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
        ->assertScript('document.querySelectorAll("[data-testid=analytics-empty-tile]").length', 7)
        ->assertPresent('@analytics-empty-summary')
        ->assertPresent('@analytics-empty-top-posts')
        ->assertPresent('@analytics-empty-metrics')
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-empty-preview] [data-testid=analytics-section-title]")).map((heading) => heading.textContent.trim()).join("|")', 'Summary|Top 5 Posts|Metrics')
        ->assertScript('document.querySelector("[data-testid=analytics-empty-summary]").innerText.replace(/\\s+/g, " ").trim()', 'Likes Comments Impressions Engagement rate')
        ->assertScript('document.querySelector("[data-testid=analytics-empty-top-posts]").children.length', 5)
        ->click('@analytics-empty-connect');

    waitForWorkspaceInsightsTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')
        ->resize(375, 812)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 2', true)
        ->assertNoJavaScriptErrors()
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

    foreach (['2026-01-01', '2026-07-01'] as $date) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => $date,
            'followers_count' => 100,
        ]);
    }

    $this->actingAs($user);
    $page = visit(route('app.insights', ['start' => '2026-01-01', 'end' => '2026-02-01']));
    waitForWorkspaceInsightsTestId($page, 'insights-range-custom');

    $page->assertMissing('@date-range-picker-trigger')
        ->assertScript('document.querySelector("[data-testid=insights-range-custom]")?.getAttribute("aria-pressed")', 'true')
        ->assertScript('document.querySelector("[data-testid=insights-range-presets] [role=group]")?.getAttribute("aria-label")', 'Período')
        ->assertPresent('@insights-range-custom');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[role="dialog"]')) return;
                if (attempt % 10 === 0) document.querySelector('[data-testid="insights-range-custom"]').click();
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertScript('document.querySelector("[role=dialog]") !== null', true)
        ->assertSeeIn('@date-range-preset-last_7_days', __('common.date_range_picker.last_7_days', [], 'pt-BR'))
        ->click('@date-range-preset-last_7_days');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (location.search.includes('start=2026-06-25')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertScript('location.search.includes("range=custom") && location.search.includes("start=2026-06-25") && location.search.includes("end=2026-07-01")', true)
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
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (new URLSearchParams(location.search).get('range') === '7d') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertScript('new URLSearchParams(location.search).get("range")', '7d')
        ->assertScript('document.querySelector("[data-testid=insights-range-7d]")?.getAttribute("aria-pressed")', 'true')
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
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="analytics-import-coverage"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

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
        ->hover('[data-testid="analytics-account-bar"] >> nth=0')
        ->assertScript('document.querySelector("[data-testid=analytics-chart-tooltip]")?.innerText.includes("1.234")', true)
        ->assertNoJavaScriptErrors()
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

    foreach ([['2026-09-01 10:00:00', 1], ['2026-09-23 10:00:00', 1866]] as [$publishedAt, $reactions]) {
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
    $page = visit(route('app.insights', ['start' => '2026-09-12', 'end' => '2026-09-23']));

    $page->assertScript('document.querySelector("[data-testid=analytics-summary-reactions-change]")?.innerText.includes('.json_encode($expectedChange).')', true)
        ->assertDontSee('+186500%')
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

    $page->assertScript('document.querySelector("[data-testid=insights-range-7d]").getBoundingClientRect().height', 0)
        ->assertSeeIn('@insights-range-mobile-trigger', '30 Days')
        ->click('@insights-range-mobile-trigger');
    waitForWorkspaceInsightsTestId($page, 'insights-range-mobile-30d-check');

    $page->assertPresent('@insights-range-mobile-30d-check')
        ->assertMissing('@insights-range-mobile-7d-check')
        ->click('@insights-range-mobile-7d');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (new URLSearchParams(location.search).get('range') === '7d') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertScript('new URLSearchParams(location.search).get("range")', '7d')
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
        'date' => '2026-09-23',
        'followers_count' => 120,
    ]);

    $this->actingAs($user);
    $page = visit(route('app.insights'))->resize(1440, 900);
    waitForWorkspaceInsightsTestId($page, 'accounts-unovis-bar-chart');
    waitForWorkspaceInsightsTestId($page, 'sidebar-footer-toggle');

    $widths = $page->script(<<<'JS'
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

    expect(array_unique($widths['during']))->toBe([$widths['before']])
        ->and($widths['after'])->toBeGreaterThan($widths['before']);
    $page->assertNoJavaScriptErrors();
});
