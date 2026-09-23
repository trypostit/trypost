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

test('workspace dashboard separates accounts and switches chart and top-post modes', function () {
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
            'snapshot_date' => '2026-09-23',
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
            'analytics_publication_id' => $publication->id,
            'reactions_count' => $followers,
            'comments_count' => 1,
        ]);
    }

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.body.innerText.includes('Total Followers')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSee('Total Followers')
        ->assertScript('Array.from(document.querySelectorAll("[data-testid=analytics-section] h2")).map((heading) => heading.textContent.trim()).join("|")', 'Summary|Top 5 Posts|Performance|Followers|Posts')
        ->assertSee('@first')
        ->assertSee('@second')
        ->assertSee('Top 5 Posts')
        ->assertSee('Performance')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->hover('[data-testid="accounts-unovis-bar-chart"] path[class$="-bar"] >> nth=0')
        ->assertPresent('[data-testid="analytics-chart-tooltip"] img[src*="/images/accounts/"]')
        ->assertMissing('[data-testid="analytics-chart-tooltip"] [style*="background-color"]')
        ->click('@followers-line')
        ->assertPresent('@followers-unovis-chart')
        ->click('@followers-growth')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->assertScript('document.querySelector("[data-testid=posts-bar]")?.getAttribute("aria-pressed")', 'true')
        ->assertScript('document.querySelector("[data-testid=posts-stacked]")?.getAttribute("aria-pressed")', 'false')
        ->assertPresent('@accounts-unovis-bar-chart')
        ->click('@posts-stacked')
        ->assertPresent('@posts-unovis-chart')
        ->hover('[data-testid="posts-unovis-chart"] path[class$="-bar"] >> nth=0')
        ->assertPresent('[data-testid="analytics-chart-tooltip"] img[src*="/images/accounts/"]')
        ->click('@top-comments')
        ->assertScript('Array.from(document.querySelectorAll("[data-slot=sidebar-inset], [data-slot=sidebar-inset] > div")).filter((element) => ["auto", "scroll"].includes(getComputedStyle(element).overflowY) && element.scrollHeight > element.clientHeight).length', 1)
        ->assertScript('(() => { const scroller = document.querySelector("[data-slot=sidebar-inset] > div"); const lastSection = Array.from(document.querySelectorAll("[data-testid=analytics-section]")).at(-1); return scroller.scrollHeight - (lastSection.getBoundingClientRect().bottom - scroller.getBoundingClientRect().top + scroller.scrollTop) >= 24; })()', true)
        ->resize(375, 812)
        ->assertScript('document.querySelector("h1")?.getBoundingClientRect().top > document.querySelector("[data-slot=sidebar-trigger]")?.getBoundingClientRect().bottom', true)
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

    $page->assertSee('Your analytics history is being prepared')
        ->assertScript('document.querySelector(".overflow-y-auto > .flex.min-h-0.flex-1.flex-col") !== null', true)
        ->assertMissing('@analytics-summary')
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
        ->assertScript('document.querySelector("h1")?.textContent.trim()', 'Analysen')
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
    $page->assertSee('Your analytics history is being prepared');

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'snapshot_date' => now('UTC')->toDateString(),
        'followers_count' => 123,
    ]);

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if (document.body.innerText.includes('Total Followers')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertSee('Total Followers')
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
    $page->assertSee('Importing account history');

    $state->update(['status' => SyncStatus::Complete]);
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.body.innerText.includes('Importing account history')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertDontSee('Importing account history')
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
        'snapshot_date' => '2026-09-23',
        'followers_count' => 1234,
    ]);

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));

    $page->assertPresent('@accounts-unovis-bar-chart')
        ->assertSee('Instagram')
        ->assertDontSee('instagram-facebook')
        ->hover('[data-testid="accounts-unovis-bar-chart"] path[class$="-bar"] >> nth=0')
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
            'analytics_publication_id' => $publication->id,
            'reactions_count' => $reactions,
        ]);
    }

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics', ['start' => '2026-09-12', 'end' => '2026-09-23']));

    $page->assertScript('document.body.innerText.includes('.json_encode($expectedChange).')', true)
        ->assertDontSee('+186500%')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
})->with([
    'English' => [Locale::English, '+186.5K%'],
    'Portuguese' => [Locale::PortugueseBrazil, "+186,5\u{00A0}mil%"],
]);
