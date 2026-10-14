<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
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
    $post = Post::factory()->forAccount($this->instagram)->published()->create(['user_id' => $this->user->id]);
    $this->mostReactions->update(['post_id' => $post->id]);
    $this->mostViews->update(['permalink' => 'https://www.instagram.com/p/most-views/']);

    $page = visit(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d']));
    waitForChannelInsightsTestId($page, 'insights-export');

    $page->click('@insights-export');
    waitForChannelInsightsTestId($page, 'insights-export-csv');
    $href = (string) $page->script('document.querySelector("[data-testid=insights-export-csv]").getAttribute("href")');
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    expect(parse_url($href, PHP_URL_PATH))->toBe(parse_url(route('app.channels.insights.download', ['account' => $this->instagram, 'format' => 'csv']), PHP_URL_PATH))
        ->and(data_get($query, 'range'))->toBe('7d');

    $page->keys('@insights-export-csv', 'Escape')
        ->assertPresent('@insights-sync-status')
        ->assertPresent('@insights-posts-about')
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-link-{$this->mostReactions->id}\"]').tagName", 'BUTTON')
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-link-{$this->mostViews->id}\"]').tagName", 'BUTTON')
        ->assertNoJavaScriptErrors();
});

test('a publication with a TryPost post opens the post details on the insights page', function () {
    $post = Post::factory()->forAccount($this->instagram)->published()->create([
        'user_id' => $this->user->id,
        'content' => 'Published through TryPost',
        'published_at' => $this->mostReactions->provider_published_at,
    ]);
    $this->mostReactions->update(['post_id' => $post->id]);
    $insightsUrl = route('app.channels.insights', $this->instagram);

    $page = visit($insightsUrl);
    waitForChannelInsightsTestId($page, "insights-posts-link-{$this->mostReactions->id}");
    $path = $page->script('location.pathname');

    $page->click("@insights-posts-link-{$this->mostReactions->id}");
    waitForChannelInsightsTestId($page, "post-details-{$post->id}");

    $page->assertSeeIn("@post-details-text-{$post->id}", 'Published through TryPost')
        ->assertScript('location.pathname', $path)
        ->assertNoJavaScriptErrors();
});

test('the post details opened from insights copy the link of the sent post', function () {
    $post = Post::factory()->forAccount($this->instagram)->published()->create([
        'user_id' => $this->user->id,
        'content' => 'Published through TryPost',
        'published_at' => $this->mostReactions->provider_published_at,
    ]);
    $post->forceFill(['platform_url' => 'https://www.instagram.com/p/COPY/'])->save();
    $this->mostReactions->update(['post_id' => $post->id]);

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, "insights-posts-link-{$this->mostReactions->id}");
    $page->click("@insights-posts-link-{$this->mostReactions->id}");
    waitForChannelInsightsTestId($page, "post-card-menu-details-{$post->id}");

    $page->script('window.__copied = null; navigator.clipboard.writeText = async (text) => { window.__copied = text; };');
    $page->click("@post-card-menu-details-{$post->id}");
    waitForChannelInsightsTestId($page, "post-copy-link-details-{$post->id}");
    $page->click("@post-copy-link-details-{$post->id}");
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100 && window.__copied === null; i++) {
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    expect($page->script('window.__copied'))->toBe('https://www.instagram.com/p/COPY/');
    $page->assertNoJavaScriptErrors();
});

test('the post details opened from insights share the link of the sent post in a new post', function () {
    $post = Post::factory()->forAccount($this->instagram)->published()->create([
        'user_id' => $this->user->id,
        'content' => 'Published through TryPost',
        'published_at' => $this->mostReactions->provider_published_at,
    ]);
    $post->forceFill(['platform_url' => 'https://www.instagram.com/p/SHARE/'])->save();
    $this->mostReactions->update(['post_id' => $post->id]);

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, "insights-posts-link-{$this->mostReactions->id}");
    $page->click("@insights-posts-link-{$this->mostReactions->id}");
    waitForChannelInsightsTestId($page, "post-card-menu-details-{$post->id}");
    $page->click("@post-card-menu-details-{$post->id}");
    waitForChannelInsightsTestId($page, "post-share-link-details-{$post->id}");
    $page->click("@post-share-link-details-{$post->id}");
    waitForChannelInsightsTestId($page, 'post-composer-dialog');

    $page->assertSeeIn('@post-composer-dialog', 'https://www.instagram.com/p/SHARE/')
        ->assertNoJavaScriptErrors();
});

test('the post details of a sent post without a link offer no share or copy link', function () {
    $post = Post::factory()->forAccount($this->instagram)->published()->create([
        'user_id' => $this->user->id,
        'content' => 'Published through TryPost',
        'published_at' => $this->mostReactions->provider_published_at,
    ]);
    $post->forceFill(['platform_url' => null])->save();
    $this->mostReactions->update(['post_id' => $post->id]);

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, "insights-posts-link-{$this->mostReactions->id}");
    $page->click("@insights-posts-link-{$this->mostReactions->id}");
    waitForChannelInsightsTestId($page, "post-card-menu-details-{$post->id}");
    $page->click("@post-card-menu-details-{$post->id}");
    waitForChannelInsightsTestId($page, "post-card-menu-content-details-{$post->id}");

    $page->assertMissing("@post-share-link-details-{$post->id}")
        ->assertMissing("@post-copy-link-details-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('on a phone the channel insights range presets collapse into a dropdown', function () {
    $page = visit(route('app.channels.insights', ['account' => $this->instagram]))->resize(390, 844);
    waitForChannelInsightsTestId($page, 'insights-range-mobile-trigger');

    $page->assertScript('document.querySelector("[data-testid=insights-range-7d]").getBoundingClientRect().height', 0)
        ->assertSeeIn('@insights-range-mobile-trigger', '30 Days')
        ->click('@insights-range-mobile-trigger');
    waitForChannelInsightsTestId($page, 'insights-range-mobile-30d-check');

    $page->assertPresent('@insights-range-mobile-30d-check')
        ->assertMissing('@insights-range-mobile-7d-check')
        ->click('@insights-range-mobile-7d');
    waitForChannelInsightsScript($page, 'new URLSearchParams(location.search).get("range") === "7d"');

    $page->assertScript('new URLSearchParams(location.search).get("range")', '7d')
        ->assertSeeIn('@insights-range-mobile-trigger', '7 Days')
        ->assertNoJavaScriptErrors();
});

test('the channel insights header sticks under the channel header with a divider like the workspace insights', function () {
    $today = CarbonImmutable::today('UTC');

    foreach (range(1, 11) as $index) {
        channelInsightsBrowserPublication($this->instagram, $today->subDays($index)->setTime(9, $index), []);
    }

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, 'insights-posts-pagination');

    $page->assertScript('getComputedStyle(document.querySelector("[data-testid=insights-toolbar]")).borderBottomWidth', '1px')
        ->assertScript('(() => { const scroller = document.querySelector("[data-testid=app-layout-scroller]"); scroller.scrollTop = scroller.scrollHeight; const header = document.querySelector("[data-testid=insights-page-header]"); const toolbar = document.querySelector("[data-testid=insights-toolbar]"); const top = scroller.getBoundingClientRect().top; const stuck = scroller.scrollTop > 0 && Math.abs(header.getBoundingClientRect().top - top) <= 1 && toolbar.getBoundingClientRect().bottom > top; scroller.scrollTop = 0; return stuck; })()', true)
        ->assertNoJavaScriptErrors();
});

test('the per post table shows ten posts per page with numbered pages kept in the url', function () {
    $today = CarbonImmutable::today('UTC');

    foreach (range(1, 11) as $index) {
        channelInsightsBrowserPublication($this->instagram, $today->subDays(1 + $index % 20)->setTime(9, $index), [
            'reactions_count' => $index,
            'comments_count' => 0,
            'views_count' => 200 + $index,
        ]);
    }

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, 'insights-posts-pagination');

    $page->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 10)
        ->assertAttribute('@insights-posts-page-1', 'aria-current', 'page')
        ->assertVisible('@insights-posts-page-2')
        ->assertMissing('@insights-posts-page-3')
        ->assertAttribute('@insights-posts-page-previous', 'disabled', '')
        ->click('@insights-posts-page-2');
    waitForChannelInsightsScript($page, 'new URLSearchParams(location.search).get("page") === "2" && document.querySelectorAll("#insights-posts-body tr").length === 3');

    $page->assertScript('new URLSearchParams(location.search).get("page")', '2')
        ->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 3)
        ->assertScript('document.querySelector("#insights-posts-body tr td").textContent.trim().startsWith("#11")', true)
        ->assertAttribute('@insights-posts-page-2', 'aria-current', 'page')
        ->assertAttribute('@insights-posts-page-next', 'disabled', '');

    $page->refresh();
    waitForChannelInsightsTestId($page, 'insights-posts-pagination');
    $page->assertAttribute('@insights-posts-page-2', 'aria-current', 'page')
        ->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 3)
        ->click('@insights-posts-page-previous');
    waitForChannelInsightsScript($page, 'document.querySelectorAll("#insights-posts-body tr").length === 10');

    $page->assertAttribute('@insights-posts-page-1', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('reels, stories and videos show the content type badge with its icon, other types show none', function () {
    $this->mostReactions->update(['content_type' => PublicationContentType::Reel]);
    $this->mostViews->update(['content_type' => PublicationContentType::Story]);
    $this->previous->update(['content_type' => PublicationContentType::Short, 'provider_published_at' => CarbonImmutable::today('UTC')->subDays(4)->setTime(10, 0)]);
    $image = channelInsightsBrowserPublication($this->instagram, CarbonImmutable::today('UTC')->subDays(3)->setTime(10, 0), ['reactions_count' => 20]);
    $image->update(['content_type' => PublicationContentType::Image]);
    $link = channelInsightsBrowserPublication($this->instagram, CarbonImmutable::today('UTC')->subDays(6)->setTime(10, 0), ['reactions_count' => 15]);
    $link->update(['content_type' => PublicationContentType::Link]);
    $video = channelInsightsBrowserPublication($this->instagram, CarbonImmutable::today('UTC')->subDays(7)->setTime(10, 0), ['reactions_count' => 12]);
    $video->update(['content_type' => PublicationContentType::Video]);

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, "insights-posts-type-{$this->mostReactions->id}");

    $page->assertVisible("@insights-posts-type-{$this->mostReactions->id}-icon")
        ->assertVisible("@insights-posts-type-{$this->mostViews->id}-icon")
        ->assertMissing("@insights-posts-type-{$image->id}")
        ->assertMissing("@insights-posts-type-{$this->previous->id}")
        ->assertMissing("@insights-posts-type-{$link->id}")
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-type-{$video->id}-icon\"]').classList.contains('tabler-icon-video')", true)
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-type-{$this->mostReactions->id}-icon\"]').classList.contains('tabler-icon-circle-play')", true)
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-type-{$this->mostViews->id}-icon\"]').classList.contains('tabler-icon-circle-dashed-plus')", true)
        ->assertNoJavaScriptErrors();
});

test('the per post table shows the post media from our bucket, a video by its cover frame', function () {
    $video = Post::factory()->forAccount($this->instagram)->published()->create(['media' => [
        ['id' => 'video', 'path' => 'medias/reel.mp4', 'url' => 'https://cdn.trypost.test/medias/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'meta' => ['cover_offset_ms' => 1500]],
    ]]);
    $image = Post::factory()->forAccount($this->instagram)->published()->create(['media' => [
        ['id' => 'image', 'path' => 'medias/a.jpg', 'url' => 'https://cdn.trypost.test/medias/a.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg'],
    ]]);
    $expired = ['thumbnail_url' => 'https://scontent.cdninstagram.com/expired.jpg?oe=6ACC450F'];
    $this->mostReactions->update(['post_id' => $video->id, 'preview_metadata' => $expired]);
    $this->mostViews->update(['post_id' => $image->id, 'preview_metadata' => $expired]);

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForChannelInsightsTestId($page, "insights-posts-video-{$this->mostReactions->id}");

    $page->assertAttribute("@insights-posts-video-{$this->mostReactions->id}", 'src', 'https://cdn.trypost.test/medias/reel.mp4#t=1.5')
        ->assertScript("document.querySelector('[data-testid=\"insights-posts-row-{$this->mostViews->id}\"] img').getAttribute('src')", 'https://cdn.trypost.test/medias/a.jpg')
        ->assertNoJavaScriptErrors();
});
