<?php

declare(strict_types=1);

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
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
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function waitForChannelInsightsTestId(mixed $page, string $testId): void
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

function waitForChannelInsightsScript(mixed $page, string $condition): void
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

function channelInsightsBrowserUser(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

/**
 * @param  array<string, mixed>  $metrics
 */
function channelInsightsBrowserPublication(SocialAccount $account, CarbonImmutable $publishedAt, array $metrics): AnalyticsPublication
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

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = channelInsightsBrowserUser();
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->user->current_workspace_id,
        'platform' => Platform::Instagram,
    ]);
    $today = CarbonImmutable::today('UTC');

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->instagram->workspace_id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $this->instagram->platform_user_id,
        'date' => $today->toDateString(),
        'followers_count' => 500,
    ]);

    $this->mostReactions = channelInsightsBrowserPublication($this->instagram, $today->subDays(2)->setTime(10, 0), [
        'reactions_count' => 50,
        'comments_count' => 3,
        'views_count' => 100,
    ]);
    $this->mostViews = channelInsightsBrowserPublication($this->instagram, $today->subDays(5)->setTime(10, 0), [
        'reactions_count' => 10,
        'comments_count' => 1,
        'views_count' => 900,
    ]);
    $this->previous = channelInsightsBrowserPublication($this->instagram, $today->subDays(40)->setTime(10, 0), [
        'reactions_count' => 5,
        'comments_count' => 0,
        'views_count' => 10,
    ]);

    $this->actingAs($this->user);
});

test('an Instagram channel shows only the metrics it reports', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, 'insights-card-views');

    $page->assertVisible('@insights-card-views')
        ->assertVisible('@insights-card-reactions')
        ->assertMissing('@insights-card-saves')
        ->assertVisible("@insights-posts-row-{$this->mostReactions->id}")
        ->assertScript('document.querySelector("[data-testid=insights-range-30d]")?.getAttribute("aria-pressed")', 'true')
        ->assertPresent('@insights-range-caption')
        ->assertNoJavaScriptErrors();
});

test('a range preset reloads the page with that range and keeps the table state', function () {
    $page = visit(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'views', 'period' => 'previous']));
    waitForChannelInsightsTestId($page, 'insights-range-7d');

    $page->click('@insights-range-7d');
    waitForChannelInsightsScript($page, 'new URLSearchParams(location.search).get("range") === "7d"');

    $page->assertScript('new URLSearchParams(location.search).get("range")', '7d')
        ->assertScript('new URLSearchParams(location.search).get("sort")', 'views')
        ->assertScript('new URLSearchParams(location.search).get("period")', 'previous')
        ->assertNoJavaScriptErrors();
});

test('sorting by views puts the most viewed publication first', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, 'insights-sort-views');

    $page->assertScript('document.querySelector("#insights-posts-body tr")?.dataset.testid', "insights-posts-row-{$this->mostReactions->id}")
        ->click('@insights-sort-views');
    waitForChannelInsightsScript($page, "document.querySelector('#insights-posts-body tr')?.dataset.testid === 'insights-posts-row-{$this->mostViews->id}'");

    $page->assertScript('document.querySelector("#insights-posts-body tr")?.dataset.testid', "insights-posts-row-{$this->mostViews->id}")
        ->assertScript('new URLSearchParams(location.search).get("sort")', 'views')
        ->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 2)
        ->assertNoJavaScriptErrors();
});

test('the previous period lists publications from the previous range', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, 'insights-period-previous');

    $page->click('@insights-period-previous');
    waitForChannelInsightsTestId($page, "insights-posts-row-{$this->previous->id}");

    $page->assertVisible("@insights-posts-row-{$this->previous->id}")
        ->assertMissing("@insights-posts-row-{$this->mostReactions->id}")
        ->assertScript('new URLSearchParams(location.search).get("period")', 'previous')
        ->assertNoJavaScriptErrors();
});

test('a LinkedIn channel explains that the network has no analytics', function () {
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->user->current_workspace_id]);

    $page = visit(route('app.channels.insights', $linkedin));
    waitForChannelInsightsTestId($page, 'insights-unsupported');

    $page->assertVisible('@insights-unsupported')
        ->assertMissing('@insights-range-presets')
        ->assertNoJavaScriptErrors();
});

test('the sidebar insights link opens the channel insights page', function () {
    $page = visit(route('app.channels.publish', $this->instagram));
    waitForChannelInsightsTestId($page, "sidebar-channel-{$this->instagram->id}-insights");

    $page->click("@sidebar-channel-{$this->instagram->id}-insights");
    waitForChannelInsightsTestId($page, 'insights-card-views');

    $page->assertScript('location.pathname', parse_url(route('app.channels.insights', $this->instagram), PHP_URL_PATH))
        ->assertVisible('@channel-insights')
        ->assertNoJavaScriptErrors();
});

test('finishing the import refreshes the available metrics and the publication table', function () {
    $fresh = SocialAccount::factory()->create([
        'workspace_id' => $this->user->current_workspace_id,
        'platform' => Platform::Instagram,
    ]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($fresh),
        'social_account_id' => $fresh->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Running,
    ]);

    $page = visit(route('app.channels.insights', $fresh));
    waitForChannelInsightsTestId($page, 'insights-no-data');
    $page->assertVisible('@insights-no-data')
        ->assertMissing('@date-range-picker-trigger');

    $publication = channelInsightsBrowserPublication($fresh, CarbonImmutable::today('UTC')->subDay()->setTime(10, 0), [
        'views_count' => 40,
        'saves_count' => 4,
    ]);
    $state->update(['status' => SyncStatus::Complete]);
    waitForChannelInsightsTestId($page, "insights-posts-row-{$publication->id}");

    $page->assertVisible("@insights-posts-row-{$publication->id}")
        ->assertVisible('@insights-card-saves')
        ->assertVisible('@insights-sort-saves')
        ->assertNoJavaScriptErrors();
});

test('the channel header exports this channel and rows open the post details or the network post', function () {
    $post = Post::factory()->published()->create(['workspace_id' => $this->instagram->workspace_id, 'user_id' => $this->user->id]);
    $destination = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->instagram->id,
        'platform' => Platform::Instagram,
    ]);
    $this->mostReactions->update(['post_platform_id' => $destination->id]);
    $this->mostViews->update(['permalink' => 'https://www.instagram.com/p/most-views/']);

    $page = visit(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d']));
    waitForChannelInsightsTestId($page, 'insights-export');

    $page->click('@insights-export');
    waitForChannelInsightsTestId($page, 'insights-export-csv');
    $href = (string) $page->script('document.querySelector("[data-testid=insights-export-csv]").getAttribute("href")');
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    expect(parse_url($href, PHP_URL_PATH))->toBe(parse_url(route('app.insights.download', 'csv'), PHP_URL_PATH))
        ->and(data_get($query, 'channels'))->toBe([$this->instagram->id])
        ->and(data_get($query, 'range'))->toBe('7d');

    $page->keys('@insights-export-csv', 'Escape')
        ->assertPresent('@insights-sync-status')
        ->assertPresent('@insights-posts-about')
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-link-{$this->mostReactions->id}\"]').getAttribute('href')", route('app.posts.index', ['post' => $post->id]))
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-link-{$this->mostViews->id}\"]').getAttribute('target')", '_blank')
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-link-{$this->mostViews->id}\"]').getAttribute('href')", 'https://www.instagram.com/p/most-views/')
        ->assertNoJavaScriptErrors();
});
