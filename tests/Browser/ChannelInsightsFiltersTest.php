<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

function waitForInsightsFilterTestId(mixed $page, string $testId): void
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

function waitForInsightsFilterScript(mixed $page, string $condition): void
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

function insightsFilterPublication(SocialAccount $account, User $user, CarbonImmutable $publishedAt, int $reactions, ?ContentType $contentType, ?WorkspaceLabel $label = null, PublicationContentType $publicationType = PublicationContentType::Image): AnalyticsPublication
{
    $post = null;

    if ($contentType !== null) {
        $post = Post::factory()->forAccount($account, $contentType)->published()->create(['user_id' => $user->id]);

        if ($label !== null) {
            $post->labels()->attach($label);
        }
    }

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'post_id' => $post?->id,
        'content_type' => $publicationType,
        'provider_published_at' => $publishedAt,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $publishedAt->toDateString(),
        'reactions_count' => $reactions,
    ]);

    return $publication;
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->user = $user->fresh();

    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Launch']);
    $today = CarbonImmutable::today('UTC');

    $this->reel = insightsFilterPublication($this->instagram, $this->user, $today->subDays(2)->setTime(10, 0), 50, ContentType::InstagramReel, $this->label);
    $this->feed = insightsFilterPublication($this->instagram, $this->user, $today->subDays(3)->setTime(10, 0), 20, ContentType::InstagramFeed);
    $this->story = insightsFilterPublication($this->instagram, $this->user, $today->subDays(4)->setTime(10, 0), 5, null, publicationType: PublicationContentType::Story);

    $this->actingAs($this->user);
});

test('the post type filter lists this platform types and narrows the URL and the totals', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForInsightsFilterTestId($page, 'insights-post-type-filter');

    $page->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 3)
        ->click('@insights-post-type-filter');
    waitForInsightsFilterTestId($page, 'insights-post-type-option-instagram_story');

    $page->assertScript('Array.from(document.querySelectorAll("[data-testid^=insights-post-type-option-]")).map((element) => element.dataset.testid.replace("insights-post-type-option-", "")).join(",")', 'instagram_feed,instagram_reel,instagram_story')
        ->click('@insights-post-type-option-instagram_story');
    waitForInsightsFilterScript($page, 'Array.from(new URLSearchParams(location.search)).filter(([key]) => key.startsWith("types[")).map(([, value]) => value).join(",") === "instagram_story" && document.querySelectorAll("#insights-posts-body tr").length === 1 && document.querySelector("[data-testid=insights-card-posts] .font-heading").textContent.trim() === "1"');

    $page->assertScript('Array.from(new URLSearchParams(location.search)).filter(([key]) => key.startsWith("types[")).map(([, value]) => value).join(",")', 'instagram_story')
        ->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 1)
        ->assertPresent("@insights-posts-row-{$this->story->id}")
        ->assertScript('document.querySelector("[data-testid=insights-card-posts] .font-heading").textContent.trim()', '1')
        ->click('@insights-post-type-clear');
    waitForInsightsFilterScript($page, '!location.search.includes("types") && document.querySelectorAll("#insights-posts-body tr").length === 3');

    $page->assertScript('location.search.includes("types")', false)
        ->assertScript('document.querySelectorAll("#insights-posts-body tr").length', 3)
        ->assertNoJavaScriptErrors();
});

test('history navigation re-syncs the label filter with the page', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForInsightsFilterTestId($page, 'insights-label-filter');

    $page->script('window.__labelTrigger0 = document.querySelector("[data-testid=insights-label-filter]").textContent.trim()');
    $page->click('@insights-range-7d');
    waitForInsightsFilterScript($page, 'new URLSearchParams(location.search).get("range") === "7d"');
    $page->click('@insights-label-filter');
    waitForInsightsFilterTestId($page, "insights-label-option-{$this->label->id}");
    $page->click("@insights-label-option-{$this->label->id}");
    waitForInsightsFilterScript($page, 'location.search.includes("labels") && document.querySelectorAll("#insights-posts-body tr").length === 1');
    $page->assertScript('document.querySelector("[data-testid=insights-label-filter]").textContent.trim() !== window.__labelTrigger0', true);

    $page->script('history.back()');
    waitForInsightsFilterScript($page, '!location.search.includes("range=7d") && !location.search.includes("labels") && document.querySelectorAll("#insights-posts-body tr").length === 3');

    $page->assertScript('document.querySelector("[data-testid=insights-label-filter]").textContent.trim() === window.__labelTrigger0', true)
        ->assertNoJavaScriptErrors();
});

test('on a phone both filters show only their icon and chevron', function () {
    $page = visit(route('app.channels.insights', $this->instagram))->resize(390, 844);
    waitForInsightsFilterTestId($page, 'insights-post-type-filter');

    foreach (['insights-label-filter', 'insights-post-type-filter'] as $filter) {
        $page->assertScript("Array.from(document.querySelectorAll('[data-testid=\"{$filter}\"] > span')).filter((element) => element.getBoundingClientRect().width > 0 && element.textContent.trim() !== '').length", 0)
            ->assertScript("document.querySelectorAll('[data-testid=\"{$filter}\"] svg').length", 2);
    }

    $page->assertScript('document.documentElement.scrollWidth <= window.innerWidth', true)
        ->assertNoJavaScriptErrors();
});

test('the post type filter search matches each word typed in any order and ignores case', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForInsightsFilterTestId($page, 'insights-post-type-filter');
    $page->click('@insights-post-type-filter');
    waitForInsightsFilterTestId($page, 'insights-post-type-search');

    $page->type('@insights-post-type-search', 'POST feed');
    waitForInsightsFilterScript($page, '!document.querySelector("[data-testid=insights-post-type-option-instagram_reel]")');

    $page->assertScript('Array.from(document.querySelectorAll("[data-testid^=insights-post-type-option-]")).map((element) => element.dataset.testid.replace("insights-post-type-option-", "")).join(",")', 'instagram_feed')
        ->assertNoJavaScriptErrors();
});

test('a YouTube channel shows no post type filter and no type tag', function (Platform $platform, ContentType $contentType, PublicationContentType $publicationType) {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->instagram->workspace_id, 'platform' => $platform]);
    $publication = insightsFilterPublication($channel, $this->user, CarbonImmutable::today('UTC')->subDays(2)->setTime(10, 0), 10, $contentType, publicationType: $publicationType);

    $page = visit(route('app.channels.insights', $channel));
    waitForInsightsFilterTestId($page, "insights-posts-row-{$publication->id}");

    $page->assertVisible('@insights-label-filter')
        ->assertMissing('@insights-post-type-filter')
        ->assertMissing("@insights-posts-type-{$publication->id}")
        ->assertNoJavaScriptErrors();
})->with([
    'YouTube' => [Platform::YouTube, ContentType::YouTubeShort, PublicationContentType::Short],
]);

test('a channel without the post type filter keeps the type tag', function (Platform $platform, ContentType $contentType) {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->instagram->workspace_id, 'platform' => $platform]);
    $publication = insightsFilterPublication($channel, $this->user, CarbonImmutable::today('UTC')->subDays(2)->setTime(10, 0), 10, $contentType, publicationType: PublicationContentType::Video);

    $page = visit(route('app.channels.insights', $channel));
    waitForInsightsFilterTestId($page, "insights-posts-row-{$publication->id}");

    $page->assertVisible('@insights-label-filter')
        ->assertMissing('@insights-post-type-filter')
        ->assertVisible("@insights-posts-type-{$publication->id}")
        ->assertNoJavaScriptErrors();
})->with([
    'X' => [Platform::X, ContentType::XPost],
    'Threads' => [Platform::Threads, ContentType::ThreadsPost],
    'Bluesky' => [Platform::Bluesky, ContentType::BlueskyPost],
    'Mastodon' => [Platform::Mastodon, ContentType::MastodonPost],
]);

test('a TikTok channel keeps the post type filter but shows no type tag', function () {
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->instagram->workspace_id, 'platform' => Platform::TikTok]);
    $publication = insightsFilterPublication($channel, $this->user, CarbonImmutable::today('UTC')->subDays(2)->setTime(10, 0), 10, ContentType::TikTokPhoto, publicationType: PublicationContentType::Video);

    $page = visit(route('app.channels.insights', $channel));
    waitForInsightsFilterTestId($page, "insights-posts-row-{$publication->id}");

    $page->assertVisible('@insights-post-type-filter')
        ->assertMissing("@insights-posts-type-{$publication->id}")
        ->assertNoJavaScriptErrors();
});

test('the post type filter leaves a gap between the search and the first type', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitForInsightsFilterTestId($page, 'insights-post-type-filter');
    $page->click('@insights-post-type-filter');
    waitForInsightsFilterTestId($page, 'insights-post-type-option-instagram_feed');

    $page->assertScript('document.querySelector("[data-testid=insights-post-type-option-instagram_feed]").getBoundingClientRect().top - document.querySelector("[data-testid=insights-post-type-search]").getBoundingClientRect().bottom >= 8', true)
        ->assertNoJavaScriptErrors();
});
