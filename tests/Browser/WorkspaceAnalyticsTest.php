<?php

declare(strict_types=1);

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
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
use Illuminate\Support\Facades\Vite;

function waitForWorkspaceAnalyticsTestId(mixed $page, string $testId): void
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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));
    waitForWorkspaceAnalyticsTestId($page, 'analytics-summary-followers');

    $page->assertVisible('@analytics-summary-followers')
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-section-title]")).map((heading) => heading.textContent.trim()).join("|")', 'Summary|Top 5 Posts|Performance|Followers|Posts')
        ->assertSee('@first')
        ->assertSee('@second')
        ->assertSee('Top 5 Posts')
        ->assertSee('Performance')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->hover('[data-testid="analytics-account-bar"] >> nth=0')
        ->assertPresent('@analytics-tooltip-icon')
        ->click('@followers-line')
        ->assertPresent('@followers-unovis-chart')
        ->click('@followers-growth')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->assertScript('document.querySelector("[data-testid=posts-bar]")?.getAttribute("aria-pressed")', 'true')
        ->assertScript('document.querySelector("[data-testid=posts-stacked]")?.getAttribute("aria-pressed")', 'false')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->click('@posts-stacked')
        ->assertPresent('@posts-unovis-chart')
        ->hover('[data-testid="analytics-post-bar"] >> nth=0')
        ->assertPresent('@analytics-tooltip-icon')
        ->click('@top-comments')
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); return ["auto", "scroll"].includes(getComputedStyle(scroller).overflowY) && scroller.scrollHeight > scroller.clientHeight; })()', true)
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); const lastSection = Array.from(document.querySelectorAll("[data-testid=analytics-section]")).at(-1); return scroller.scrollHeight - (lastSection.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + scroller.scrollTop) >= 24; })()', true)
        ->resize(375, 812)
        ->assertScript('document.querySelector("[data-testid=analytics-page-header]")?.getBoundingClientRect().top > document.querySelector("[data-testid=app-sidebar-trigger]")?.getBoundingClientRect().bottom', true)
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth + 2', true)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('workspace dashboard explains the empty state without inventing follower totals', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));

    $this->actingAs($user);
    $page = visit(route('app.analytics'));

    $page->assertVisible('@analytics-empty-state')
        ->assertPresent('@app-layout-content')
        ->assertMissing('@analytics-summary')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('date presets use the latest available observation when analytics history is old', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics', ['start' => '2026-01-01', 'end' => '2026-02-01']));
    waitForWorkspaceAnalyticsTestId($page, 'date-range-picker-trigger');

    $page->assertScript('document.querySelector("[data-testid=date-range-picker-trigger]")?.textContent.includes("janeiro")', true)
        ->click('@date-range-picker-trigger');
    waitForWorkspaceAnalyticsTestId($page, 'date-range-preset-last_30_days');

    $page->assertMissing('@date-range-preset-today')
        ->click('@date-range-preset-last_30_days');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const query = new URLSearchParams(location.search);
                if (query.get('start') === '2026-06-02' && query.get('end') === '2026-07-01') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertScript('new URLSearchParams(location.search).get("start")', '2026-06-02')
        ->assertScript('new URLSearchParams(location.search).get("end")', '2026-07-01')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('workspace dashboard uses its own localized page title instead of the sidebar label', function () {
    $user = User::factory()->create(['locale' => Locale::German]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));

    visit(route('app.analytics'))
        ->assertScript('document.querySelector("[data-testid=analytics-page-header]")?.textContent.includes("Analysen")', true)
        ->assertScript('document.title.includes("Analysen")', true)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('dashboard refreshes when the first analytics snapshot arrives after opening the page', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));
    waitForWorkspaceAnalyticsTestId($page, 'analytics-empty-state');
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

    waitForWorkspaceAnalyticsTestId($page, 'analytics-summary-followers');

    $page->assertVisible('@analytics-summary-followers')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('import progress disappears after the queued backfill finishes without navigating away', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));
    waitForWorkspaceAnalyticsTestId($page, 'analytics-import-coverage');
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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
        'date' => '2026-09-23',
        'followers_count' => 1234,
    ]);

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));

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
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
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
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics', ['start' => '2026-09-12', 'end' => '2026-09-23']));

    $page->assertScript('document.querySelector("[data-testid=analytics-summary-reactions-change]")?.innerText.includes('.json_encode($expectedChange).')', true)
        ->assertDontSee('+186500%')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with([
    'English' => [Locale::English, '+186.5K%'],
    'Portuguese' => [Locale::PortugueseBrazil, "+186,5\u{00A0}mil%"],
]);
