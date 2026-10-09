<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Post\Status as PostStatus;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function waitForPostDetailsDialogCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForPostDetailsDialogTestId(mixed $page, string $testId): void
{
    waitForPostDetailsDialogCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

/**
 * @return array{0: User, 1: SocialAccount}
 */
function postDetailsDialogSetup(): array
{
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user, SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC'])];
}

/**
 * @param  array<string, float>  $metrics
 */
function postDetailsDialogPost(SocialAccount $account, int $images = 0, array $metrics = [], PublicationContentType $contentType = PublicationContentType::Image): Post
{
    $post = Post::factory()->forAccount($account, ContentType::InstagramFeed)->published()->create([
        'content' => 'Details dialog post',
        'platform_url' => 'https://www.instagram.com/p/abc/',
        'published_at' => now()->subHour(),
        'media' => $images === 0 ? [] : collect(range(1, $images))->map(fn (int $index): array => [
            'id' => (string) Str::uuid(),
            'path' => "medias/photo-{$index}.jpg",
            'url' => "https://cdn.example.test/photo-{$index}.jpg",
            'type' => 'image',
            'mime_type' => 'image/jpeg',
            'original_filename' => "photo-{$index}.jpg",
            'size' => 1024,
        ])->all(),
    ]);

    if ($metrics !== []) {
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'post_id' => $post->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'content_type' => $contentType,
            'remote_id' => $post->platform_post_id,
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'collected_at' => now()->subHours(2),
            'metrics' => collect($metrics)
                ->map(fn (float $value, string $key): array => [
                    'value' => $value,
                    'unit' => str_contains($key, 'milliseconds') ? 'milliseconds' : 'count',
                    'availability' => 'available',
                ])
                ->all(),
        ]);
    }

    return $post;
}

test('clicking a sent card opens the post details, and so does the menu', function () {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForPostDetailsDialogTestId($page, "post-open-{$post->id}");
    $before = $page->script('location.href');

    $page->click("@post-open-{$post->id}");
    waitForPostDetailsDialogTestId($page, "post-details-{$post->id}");

    expect($page->script('location.href'))->toBe($before);
    $page->assertPresent("@post-details-status-{$post->id}")
        ->assertSeeIn("@post-details-text-{$post->id}", 'Details dialog post');

    $page->keys("@post-details-{$post->id}", 'Escape');
    waitForPostDetailsDialogCondition($page, "!document.querySelector('[data-testid=\"post-details-{$post->id}\"]')");
    $page->click("@post-card-menu-{$post->id}");
    waitForPostDetailsDialogTestId($page, "post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitForPostDetailsDialogTestId($page, "post-details-{$post->id}");

    $page->assertNoJavaScriptErrors();
});

test('the post details deep link opens the dialog on the sent tab', function () {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForPostDetailsDialogTestId($page, "post-details-{$post->id}");

    $page->assertVisible("@post-details-{$post->id}")
        ->assertSeeIn("@post-details-status-{$post->id}", __('posts.status.published'))
        ->assertNoJavaScriptErrors();
    expect($page->script('new URLSearchParams(location.search).get("post")'))->toBe($post->id);
});

test('the post details of a google business post in review say google is reviewing it', function () {
    [$user, $account] = postDetailsDialogSetup();
    $channel = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $account->workspace_id, 'timezone' => 'UTC']);
    $post = Post::factory()->forAccount($channel)->pendingReview()->create(['content' => 'Waiting for Google', 'scheduled_at' => now()->subMinute()]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForPostDetailsDialogTestId($page, "post-details-{$post->id}");

    $page->assertSeeIn("@post-details-status-{$post->id}", __('posts.publish.in_google_review'))
        ->assertNoJavaScriptErrors();
});

test('the details media sits below the text in a single scrollable row', function (int $images, bool $overflows) {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account, $images);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]))->resize(1280, 900);
    waitForPostDetailsDialogTestId($page, "post-details-media-{$post->id}");
    waitForPostDetailsDialogCondition($page, "document.getAnimations().every((animation) => animation.playState !== 'running')");

    $layout = $page->script(<<<JS
        (() => {
            const strip = document.querySelector('[data-testid="post-details-media-{$post->id}"]');
            const text = document.querySelector('[data-testid="post-details-text-{$post->id}"]');
            const items = [...strip.querySelectorAll('[data-testid^="post-details-media-item-"]')];

            return {
                count: items.length,
                tops: [...new Set(items.map((item) => Math.round(item.getBoundingClientRect().top)))].length,
                sizes: [...new Set(items.map((item) => Math.round(item.getBoundingClientRect().width)))],
                belowText: strip.getBoundingClientRect().top >= text.getBoundingClientRect().bottom,
                overflows: strip.scrollWidth > strip.clientWidth,
            };
        })()
    JS);

    expect($layout)->toBe([
        'count' => $images,
        'tops' => 1,
        'sizes' => [88],
        'belowText' => true,
        'overflows' => $overflows,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'eight images' => [8, true],
]);

test('the details metrics band scrolls with arrows, shows when it refreshed and links to the channel insights', function () {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account, 0, [
        'views' => 900,
        'reach' => 600,
        'reactions' => 40,
        'comments' => 7,
        'shares' => 3,
    ]);
    $band = "post-details-metrics-{$post->id}";
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]))->resize(390, 844);
    waitForPostDetailsDialogTestId($page, $band);
    waitForPostDetailsDialogCondition($page, "document.getAnimations().every((animation) => animation.playState !== 'running')");
    waitForPostDetailsDialogTestId($page, "{$band}-next");

    $page->assertVisible("@{$band}-next")
        ->assertPresent("@{$band}-fade-next")
        ->assertNotPresent("@{$band}-prev")
        ->assertNotPresent("@{$band}-fade-prev")
        ->assertMissing("@{$band}-refreshed");

    $page->click("@{$band}-next");
    waitForPostDetailsDialogTestId($page, "{$band}-prev");
    $page->assertVisible("@{$band}-prev")
        ->assertPresent("@{$band}-fade-prev");

    $page->script("(() => { const strip = document.querySelector('[data-testid=\"{$band}\"]'); strip.scrollLeft = strip.scrollWidth; })()");
    waitForPostDetailsDialogCondition($page, "!document.querySelector('[data-testid=\"{$band}-next\"]')");
    $page->assertNotPresent("@{$band}-next")
        ->assertNotPresent("@{$band}-fade-next");

    $page->hover("@{$band}");
    waitForPostDetailsDialogTestId($page, "{$band}-refreshed");
    $page->assertVisible("@{$band}-refreshed")
        ->assertSeeIn("@{$band}-refreshed", __('posts.publish.metrics.refreshed', ['time' => '2 hours ago']));

    $insightsPath = parse_url(route('app.channels.insights', $account), PHP_URL_PATH);

    expect($page->script("new URL(document.querySelector('[data-testid=\"post-details-insights-{$post->id}\"]').href).pathname"))
        ->toBe($insightsPath);

    $page->click("@post-details-insights-{$post->id}");
    waitForPostDetailsDialogCondition($page, "location.pathname === '{$insightsPath}'");

    $page->assertPathIs($insightsPath)
        ->assertNoJavaScriptErrors();
});

test('the details metrics follow the order of the post content type and network', function (Platform $platform, PublicationContentType $contentType, array $expected) {
    [$user, $instagram] = postDetailsDialogSetup();
    $account = $platform === Platform::Instagram
        ? $instagram
        : SocialAccount::factory()->create(['workspace_id' => $instagram->workspace_id, 'platform' => $platform, 'timezone' => 'UTC']);
    $post = postDetailsDialogPost($account, 0, collect([
        'impressions', 'reach', 'views', 'replies', 'comments', 'reactions', 'shares', 'saves', 'follows',
        'engagement_rate', 'watch_time_milliseconds', 'average_watch_time_milliseconds', 'reposts', 'quotes', 'bookmarks',
    ])->mapWithKeys(fn (string $key, int $index): array => [$key => $index + 1])->all(), $contentType);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForPostDetailsDialogTestId($page, "post-details-metrics-{$post->id}");

    expect($page->script("[...document.querySelectorAll('[data-testid=\"post-details-metrics-{$post->id}\"] [data-metric]')].map((item) => item.dataset.metric)"))
        ->toBe($expected);
    $page->assertNoJavaScriptErrors();
})->with([
    'instagram reel' => [Platform::Instagram, PublicationContentType::Reel, ['reactions', 'comments', 'engagement_rate', 'views', 'shares', 'saves', 'watch_time_milliseconds', 'average_watch_time_milliseconds', 'reach']],
]);

function postDetailsDialogMetricsBandState(mixed $page, string $band): array
{
    return $page->script(<<<JS
        (() => {
            const strip = document.querySelector('[data-testid="{$band}"]');

            return {
                overflows: strip.scrollWidth > strip.clientWidth,
                next: !!document.querySelector('[data-testid="{$band}-next"]'),
                fadeNext: !!document.querySelector('[data-testid="{$band}-fade-next"]'),
            };
        })()
    JS);
}

test('the metrics band arrows follow the real overflow when the metrics change width after mounting', function (string $surface, int $width) {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account, 2, [
        'reactions' => 1234,
        'comments' => 56,
        'engagement_rate' => 4.2,
        'views' => 98765,
        'shares' => 12,
        'saves' => 34,
        'follows' => 5,
        'reach' => 54321,
    ], PublicationContentType::Carousel);
    $band = $surface === 'dialog' ? "post-details-metrics-{$post->id}" : "post-metrics-{$post->id}";
    $this->actingAs($user);

    $page = visit($surface === 'dialog'
        ? route('app.posts.index', ['post' => $post->id])
        : route('app.posts.index', ['tab' => 'sent']))->resize($width, 1000);
    waitForPostDetailsDialogTestId($page, $band);
    waitForPostDetailsDialogCondition($page, "document.fonts.status === 'loaded' && document.getAnimations().every((animation) => animation.playState !== 'running')");

    expect(postDetailsDialogMetricsBandState($page, $band))->toBe(['overflows' => true, 'next' => true, 'fadeNext' => true]);

    $page->script(<<<JS
        (() => {
            const style = document.createElement('style');
            style.id = 'narrow-metrics';
            style.textContent = '[data-testid="{$band}"] [data-metric] * { letter-spacing: -5px; } [data-testid="{$band}"] [data-metric] svg { display: none; }';
            document.head.append(style);
        })()
    JS);
    waitForPostDetailsDialogCondition($page, "!document.querySelector('[data-testid=\"{$band}-next\"]')");

    expect(postDetailsDialogMetricsBandState($page, $band))->toBe(['overflows' => false, 'next' => false, 'fadeNext' => false]);

    $page->script("document.getElementById('narrow-metrics').remove()");
    waitForPostDetailsDialogTestId($page, "{$band}-next");

    expect(postDetailsDialogMetricsBandState($page, $band))->toBe(['overflows' => true, 'next' => true, 'fadeNext' => true]);
    $page->assertNoJavaScriptErrors();
})->with([
    'dialog' => ['dialog', 1280],
    'card' => ['card', 1920],
]);

test('a publishing post shows a spinning status in its details', function () {
    [$user, $account] = postDetailsDialogSetup();
    $post = postDetailsDialogPost($account);
    $post->update(['status' => PostStatus::Publishing]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForPostDetailsDialogTestId($page, "post-details-status-{$post->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-details-status-{$post->id}\"] svg').classList.contains('animate-spin')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the details of a sent thread show every post of the thread with its own media', function () {
    [$user, $instagram] = postDetailsDialogSetup();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $instagram->workspace_id, 'timezone' => 'UTC', 'meta' => ['x_verified_type' => 'blue']]);
    $post = Post::factory()->forAccount($account, ContentType::XPost)->published()->create([
        'content' => 'First post of the thread',
        'published_at' => now()->subHour(),
        'meta' => ['thread_replies' => [
            ['text' => 'Second post of the thread', 'media' => [[
                'id' => (string) Str::uuid(),
                'path' => 'medias/reply.jpg',
                'url' => 'https://cdn.example.test/reply.jpg',
                'type' => 'image',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'reply.jpg',
                'size' => 1024,
            ]]],
            'Third post of the thread',
        ]],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForPostDetailsDialogTestId($page, "thread-view-{$post->id}");

    $page->assertSeeIn("@thread-view-post-{$post->id}-0", 'First post of the thread')
        ->assertSeeIn("@thread-view-post-{$post->id}-1", 'Second post of the thread')
        ->assertPresent("@post-details-media-item-{$post->id}-1-0")
        ->assertSeeIn("@thread-view-post-{$post->id}-2", 'Third post of the thread')
        ->assertNotPresent("@post-details-text-{$post->id}")
        ->assertPresent("@thread-view-connector-{$post->id}-0")
        ->assertNotPresent('[data-testid^="post-details-target-"]')
        ->assertNotPresent("@thread-view-connector-{$post->id}-2")
        ->assertScript("document.querySelectorAll('[data-testid=\"thread-view-{$post->id}\"] [data-verified=\"blue\"]').length", 3)
        ->assertNoJavaScriptErrors();
});
