<?php

declare(strict_types=1);

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function waitForInsightsParityCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForInsightsParityTestId(mixed $page, string $testId): void
{
    waitForInsightsParityCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

const INSIGHTS_PARITY_AVATAR = 'data:image/svg+xml;utf8,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%2240%22 height=%2240%22%3E%3Crect width=%2240%22 height=%2240%22 fill=%22%23e11d48%22/%3E%3C/svg%3E';

/**
 * @return array{user: User, instagram: SocialAccount, facebook: SocialAccount, post: Post}
 */
function insightsParitySetup(): array
{
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = [];
    $post = null;

    foreach ([[Platform::Instagram, 'alpha', 120, 40], [Platform::Facebook, 'bravo', 300, 9]] as [$platform, $username, $followers, $reactions]) {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
            'username' => $username,
            'timezone' => 'UTC',
        ]);
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => $platform,
            'network' => $platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'account_username' => $username,
            'account_avatar_url' => $platform === Platform::Instagram ? INSIGHTS_PARITY_AVATAR : null,
            'date' => now('UTC')->subDays(2)->toDateString(),
            'followers_count' => $followers,
        ]);
        $created = Post::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'content' => "Insights parity post {$username}",
            'published_at' => now()->subDays(3),
        ]);
        $destination = PostPlatform::factory()->published()->create([
            'post_id' => $created->id,
            'social_account_id' => $account->id,
            'platform' => $platform,
            'published_at' => now()->subDays(3),
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'post_platform_id' => $destination->id,
            'platform' => $platform,
            'network' => $platform->network(),
            'account_username' => $username,
            'account_avatar_url' => $platform === Platform::Instagram ? INSIGHTS_PARITY_AVATAR : null,
            'excerpt' => "Insights parity post {$username}",
            'provider_published_at' => now('UTC')->subDays(3),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => now('UTC')->subDays(2)->toDateString(),
            'reactions_count' => $reactions,
            'comments_count' => 2,
            'views_count' => $reactions * 10,
        ]);
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => SyncStatus::Complete,
            'last_success_at' => now()->subHours(2),
        ]);

        $accounts[$username] = $account;
        $post ??= $created;
    }

    return ['user' => $user, 'instagram' => $accounts['alpha'], 'facebook' => $accounts['bravo'], 'post' => $post];
}

test('the header offers CSV and Markdown exports of the filtered page and explains the sync cadence', function () {
    ['user' => $user, 'instagram' => $instagram] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights', ['range' => '7d', 'channels' => [$instagram->id]]));
    waitForInsightsParityTestId($page, 'insights-export');

    $page->click('@insights-export');
    waitForInsightsParityTestId($page, 'insights-export-csv');

    $csv = (string) $page->script('document.querySelector("[data-testid=insights-export-csv]").getAttribute("href")');
    $markdown = (string) $page->script('document.querySelector("[data-testid=insights-export-md]").getAttribute("href")');
    parse_str((string) parse_url($csv, PHP_URL_QUERY), $query);

    expect(parse_url($csv, PHP_URL_PATH))->toBe(parse_url(route('app.insights.download', 'csv'), PHP_URL_PATH))
        ->and(parse_url($markdown, PHP_URL_PATH))->toBe(parse_url(route('app.insights.download', 'md'), PHP_URL_PATH))
        ->and(data_get($query, 'range'))->toBe('7d')
        ->and(data_get($query, 'channels'))->toBe([$instagram->id]);

    $page->assertSeeIn('@insights-export-csv', 'CSV')
        ->assertSeeIn('@insights-export-md', 'Markdown')
        ->keys('@insights-export-csv', 'Escape');

    $page->assertScript('getComputedStyle(document.querySelector("[data-testid=insights-sync-status]")).backgroundColor !== "rgba(0, 0, 0, 0)"', true)
        ->click('@insights-sync-status');
    waitForInsightsParityTestId($page, 'insights-sync-popover');

    $page->assertSeeIn('@insights-sync-popover', 'How Insights stay updated')
        ->assertSeeIn('@insights-sync-new-posts', 'every 3 hours')
        ->assertSeeIn('@insights-sync-new-posts', 'every 24 hours on X')
        ->assertSeeIn('@insights-sync-metrics', 'last 30 days (20 on X)')
        ->assertSeeIn('@insights-sync-last', 'Last synced 2 hours ago')
        ->assertNoJavaScriptErrors();
});

test('summary cards and sections explain themselves with info tooltips', function () {
    ['user' => $user] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-summary-posts-about');

    foreach (['posts', 'followers', 'reactions', 'comments', 'engagement_rate'] as $metric) {
        $page->assertPresent("@analytics-summary-{$metric}-about");
    }

    foreach (['analytics-top-posts-about', 'analytics-performance-about', 'analytics-followers-about', 'analytics-posts-about'] as $testId) {
        $page->assertPresent("@{$testId}");
    }

    $page->hover('@analytics-summary-posts-about');
    waitForInsightsParityTestId($page, 'analytics-summary-posts-about-content');
    $page->assertSeeIn('@analytics-summary-posts-about-content', 'Posts published in the period.');

    $page->hover('@analytics-performance-about');
    waitForInsightsParityTestId($page, 'analytics-performance-about-content');
    $page->assertSeeIn('@analytics-performance-about-content', 'Totals per channel')
        ->assertNoJavaScriptErrors();
});

test('the performance columns picker changes the table and remembers the choice', function () {
    ['user' => $user] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-performance-columns');

    $headers = 'Array.from(document.querySelectorAll("[data-testid^=analytics-performance-column-]")).map((cell) => cell.dataset.testid.replace("analytics-performance-column-", "")).join(",")';

    $page->assertScript($headers, 'posts,reactions,comments,engagement_rate')
        ->click('@analytics-performance-columns');
    waitForInsightsParityTestId($page, 'analytics-performance-columns-menu');

    $page->assertSeeIn('@analytics-performance-columns-menu', 'Columns')
        ->assertScript('document.querySelector("[data-testid=analytics-performance-columns-posts] [role=checkbox]")?.getAttribute("data-state")', 'checked')
        ->assertScript('document.querySelector("[data-testid=analytics-performance-columns-views] [role=checkbox]")?.getAttribute("data-state")', 'unchecked')
        ->assertScript('(() => { const box = document.querySelector("[data-testid=analytics-performance-columns-posts] [role=checkbox]"); return getComputedStyle(box.querySelector("svg")).color === getComputedStyle(box).color; })()', true)
        ->assertScript('document.querySelectorAll("[data-testid^=analytics-performance-columns-]:not([data-testid=analytics-performance-columns-menu])").length', 14)
        ->click('@analytics-performance-columns-views')
        ->click('@analytics-performance-columns-comments');

    $triggerRect = 'JSON.stringify((({ left, top }) => [Math.round(left), Math.round(top)])(document.querySelector("[data-testid=analytics-performance-columns]").getBoundingClientRect()))';
    $before = $page->script($triggerRect);
    foreach (['reposts', 'impressions', 'clicks', 'shares', 'saves', 'reach'] as $metric) {
        $page->click("@analytics-performance-columns-{$metric}");
    }
    $page->assertVisible('@analytics-performance-columns-menu')
        ->assertScript($triggerRect, $before);
    foreach (['reposts', 'impressions', 'clicks', 'shares', 'saves', 'reach'] as $metric) {
        $page->click("@analytics-performance-columns-{$metric}");
    }
    waitForInsightsParityCondition($page, "{$headers} === 'posts,reactions,engagement_rate,views'");

    $page->assertScript($headers, 'posts,reactions,engagement_rate,views')
        ->assertSeeIn('@analytics-performance-column-views', 'Views');

    $page->navigate(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-performance-columns');

    $page->assertScript($headers, 'posts,reactions,engagement_rate,views')
        ->assertNoJavaScriptErrors();
});

test('followers and posts charts show network logos and say how many channels are shown', function () {
    ['user' => $user, 'instagram' => $instagram] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'accounts-unovis-bar-chart');
    waitForInsightsParityCondition($page, 'document.querySelectorAll("[data-testid=analytics-axis-logo]").length >= 4');

    $page->assertScript('document.querySelectorAll("[data-testid=analytics-axis-logo]").length', 4)
        ->assertScript('document.querySelector("[data-testid=analytics-followers-subtitle]")?.textContent.includes("Showing")', false);

    $page->navigate(route('app.insights', ['channels' => [$instagram->id]]));
    waitForInsightsParityTestId($page, 'analytics-followers-subtitle');
    waitForInsightsParityCondition($page, 'document.querySelectorAll("[data-testid=analytics-axis-logo]").length >= 2');

    $page->assertSeeIn('@analytics-followers-subtitle', 'Showing 1 of 2 channels. Filter by channel to see a different set.')
        ->assertSeeIn('@analytics-posts-subtitle', 'Showing 1 of 2 channels.')
        ->assertScript('document.querySelectorAll("[data-testid=analytics-axis-logo]").length', 2)
        ->assertScript('document.querySelector("[data-testid=analytics-axis-logo]").getAttribute("aria-label")', 'Instagram')
        ->assertNoJavaScriptErrors();
});

test('performance and follower charts show each channel as avatar plus network badge', function () {
    ['user' => $user] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'accounts-unovis-bar-chart');
    waitForInsightsParityCondition($page, 'document.querySelectorAll("[data-testid=analytics-axis-channel]").length >= 4');

    $page->assertScript('document.querySelectorAll("[data-testid=analytics-axis-avatar]").length', 2)
        ->assertScript('document.querySelectorAll("[data-testid=analytics-axis-avatar-fallback]").length', 4)
        ->assertScript('document.querySelectorAll("[data-testid=analytics-axis-channel] [data-testid=analytics-axis-logo]").length', 4)
        ->assertScript('document.querySelectorAll("[data-testid=analytics-top-post-channel]").length >= 2', true)
        ->assertNoJavaScriptErrors();
});

test('a channel whose connection is lost shows the disconnected dot in the filter, the table and the charts', function () {
    ['user' => $user, 'facebook' => $facebook] = insightsParitySetup();
    $facebook->update(['status' => SocialAccountStatus::TokenExpired]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-channel-filter');
    waitForInsightsParityCondition($page, 'document.querySelectorAll("[data-testid=analytics-axis-disconnected]").length >= 2');

    $page->assertScript('document.querySelectorAll("[data-testid=analytics-axis-disconnected]").length', 2)
        ->assertScript("document.querySelectorAll('[data-testid=\"channel-avatar-disconnected-{$facebook->id}\"]').length >= 1", true)
        ->click('@analytics-channel-filter');
    waitForInsightsParityCondition($page, "document.querySelectorAll('[data-testid=\"channel-avatar-disconnected-{$facebook->id}\"]').length >= 2");

    $page->assertNoJavaScriptErrors();
});

test('clicking a top post opens its post details on the publish page', function () {
    ['user' => $user, 'post' => $post] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-top-post-link');

    $page->click('[data-testid="analytics-top-post"] >> nth=0');
    waitForInsightsParityTestId($page, "post-details-{$post->id}");

    $page->assertScript('new URLSearchParams(location.search).get("post")', $post->id)
        ->assertScript('location.pathname', parse_url(route('app.posts.index'), PHP_URL_PATH))
        ->assertSeeIn("@post-details-text-{$post->id}", 'Insights parity post alpha')
        ->assertNoJavaScriptErrors();
});

test('performance columns sort ascending and descending, with the columns menu above the table', function () {
    ['user' => $user] = insightsParitySetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForInsightsParityTestId($page, 'analytics-performance-sort-reactions');

    $order = 'Array.from(document.querySelectorAll("tbody th[scope=row]")).map((cell) => (cell.innerText.match(/@\\w+/) || [""])[0]).join(",")';

    $page->assertScript('document.querySelector("[data-testid=analytics-performance-columns]").closest("table") === null', true)
        ->click('@analytics-performance-sort-reactions');
    waitForInsightsParityCondition($page, 'document.querySelector("[data-testid=analytics-performance-column-reactions]").getAttribute("aria-sort") === "descending"');
    $descending = $page->script($order);

    $page->click('@analytics-performance-sort-reactions');
    waitForInsightsParityCondition($page, 'document.querySelector("[data-testid=analytics-performance-column-reactions]").getAttribute("aria-sort") === "ascending"');
    $ascending = $page->script($order);

    expect($descending)->toContain('@alpha')
        ->and($ascending)->toBe(implode(',', array_reverse(explode(',', $descending))));

    $page->click('@analytics-performance-sort-channel');
    waitForInsightsParityCondition($page, 'document.querySelector("[data-testid=analytics-performance-channel-header]").getAttribute("aria-sort") === "ascending"');

    expect($page->script($order))->toBe('@alpha,@bravo');
    $page->assertNoJavaScriptErrors();
});
