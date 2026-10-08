<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function waitForImportedCardTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return list<string>
 */
function importedCardMenuItems(mixed $page, string $key): array
{
    return $page->script(<<<JS
        Array.from(document.querySelectorAll('[data-testid="post-card-menu-content-{$key}"] [role="menuitem"]'))
            .map((element) => element.dataset.testid.replace('-{$key}', ''))
    JS);
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function importedCardSetup(): array
{
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user, $workspace, SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC'])];
}

function importedCardPost(Workspace $workspace, SocialAccount $account, ContentType $contentType = ContentType::InstagramReel): Post
{
    $post = Post::factory()->imported()->create([
        'workspace_id' => $workspace->id,
        'content' => 'Posted straight from the app',
        'published_at' => now()->subHour(),
        'media' => [[
            'id' => (string) Str::uuid(),
            'path' => 'medias/clip.mp4',
            'url' => 'https://cdn.example.test/clip.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'original_filename' => 'clip.mp4',
            'size' => 1024,
        ]],
    ]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => $contentType,
        'platform_url' => 'https://www.instagram.com/reel/abc/',
        'published_at' => now()->subHour(),
    ]);
    importedCardMetrics($workspace, $account, $target);

    return $post;
}

function importedCardMetrics(Workspace $workspace, SocialAccount $account, PostPlatform $target): void
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $target->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'remote_id' => $target->platform_post_id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => [
            'reactions' => ['value' => 12, 'unit' => 'count', 'availability' => 'available'],
            'comments' => ['value' => 3, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);
}

test('an imported sent card looks like a sent post and offers only the allowed actions', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $post = importedCardPost($workspace, $account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForImportedCardTestId($page, "post-card-{$post->id}");

    $page->assertSeeIn("@post-card-{$post->id}", __('posts.content_types.instagram_reel.label'))
        ->assertSeeIn("@post-card-{$post->id}", 'Posted straight from the app')
        ->assertPresent("@post-thumbnail-video-{$post->id}-0")
        ->assertPresent("@post-metrics-{$post->id}")
        ->assertSeeIn("@post-published-via-{$post->id}", __('posts.publish.published_via'))
        ->assertSeeIn("@post-published-via-{$post->id}", 'Instagram')
        ->assertPresent("@post-published-via-icon-{$post->id}")
        ->assertPresent("@post-time-{$post->id}")
        ->assertAttribute("@post-schedule-mode-{$post->id}", 'data-mode', 'custom')
        ->assertMissing("@post-recurring-{$post->id}")
        ->assertMissing("@post-edit-{$post->id}")
        ->assertMissing("@post-publish-now-{$post->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-published-via-{$post->id}\"] img') === null"))
        ->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"post-published-via-icon-{$post->id}\"]').tagName.toLowerCase()"))
        ->toBe('svg');

    $page->script("document.querySelector('[data-testid=\"post-published-via-{$post->id}\"]').dispatchEvent(new PointerEvent('pointermove', {bubbles: true, pointerType: 'mouse'}))");
    waitForImportedCardTestId($page, "post-published-via-tooltip-{$post->id}");
    $page->assertSeeIn("@post-published-via-tooltip-{$post->id}", __('posts.publish.published_directly_from', ['network' => 'Instagram']));

    expect($page->script("document.querySelector('[data-testid=\"post-thumbnail-video-{$post->id}-0\"]').getAttribute('src')"))
        ->toEndWith('#t=0.1');

    expect($page->script("document.querySelector('[data-testid=\"post-view-{$post->id}\"]').getAttribute('href')"))
        ->toBe('https://www.instagram.com/reel/abc/')
        ->and($page->script("document.querySelectorAll('[data-testid=\"post-metrics-{$post->id}\"] svg').length > 0"))
        ->toBeTrue();

    $page->click("@post-card-menu-{$post->id}");
    waitForImportedCardTestId($page, "post-card-menu-content-{$post->id}");

    expect(importedCardMenuItems($page, $post->id))->toBe(['post-duplicate', 'post-details-open']);

    $page->click("@post-details-open-{$post->id}");
    waitForImportedCardTestId($page, "post-details-{$post->id}");

    $page->assertSeeIn("@post-details-published-via-{$post->id}", 'Instagram')
        ->assertPresent("@post-details-published-via-icon-{$post->id}")
        ->assertPresent("@post-details-metrics-{$post->id}")
        ->assertPresent("@post-details-insights-{$post->id}")
        ->assertMissing("@post-details-created-by-{$post->id}")
        ->assertMissing("@post-details-rail-{$post->id}")
        ->assertMissing("@post-details-edit-{$post->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-details-view-{$post->id}\"]').getAttribute('href')"))
        ->toBe('https://www.instagram.com/reel/abc/')
        ->and($page->script("document.querySelectorAll('[data-testid=\"post-details-metrics-{$post->id}\"] svg').length > 0"))
        ->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('content type badges preserve the formats shown on other platforms', function (Platform $platform, array $formats) {
    [$user, $workspace] = importedCardSetup();
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
    ]);
    $expectations = [];

    foreach ($formats as [$contentType, $showsBadge]) {
        $post = Post::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        PostPlatform::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $platform,
            'content_type' => $contentType,
        ]);
        $expectations[] = [$post, $contentType, $showsBadge];
    }

    $this->actingAs($user);
    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    foreach ($expectations as [$post, $contentType, $showsBadge]) {
        waitForImportedCardTestId($page, "post-card-{$post->id}");
        $page->assertPresent("@post-card-{$post->id}");

        if ($showsBadge) {
            $page->assertSeeIn("@post-content-type-{$post->id}", __("posts.content_types.{$contentType->value}.label"));
        } else {
            $page->assertMissing("@post-content-type-{$post->id}");
        }
    }

    $page->assertNoJavaScriptErrors();
})->with([
    'Instagram via Facebook' => [Platform::InstagramFacebook, [
        [ContentType::InstagramFeed, false],
        [ContentType::InstagramReel, true],
        [ContentType::InstagramStory, true],
    ]],
    'Facebook' => [Platform::Facebook, [
        [ContentType::FacebookPost, false],
        [ContentType::FacebookReel, true],
        [ContentType::FacebookStory, true],
    ]],
    'Threads' => [Platform::Threads, [
        [ContentType::ThreadsPost, false],
        [ContentType::ThreadsGhostPost, true],
    ]],
    'Pinterest' => [Platform::Pinterest, [
        [ContentType::PinterestPin, false],
        [ContentType::PinterestVideoPin, true],
        [ContentType::PinterestCarousel, true],
    ]],
    'YouTube' => [Platform::YouTube, [[ContentType::YouTubeShort, false]]],
    'LinkedIn' => [Platform::LinkedIn, [[ContentType::LinkedInPost, false]]],
    'X' => [Platform::X, [[ContentType::XPost, false]]],
]);

test('on a phone an imported sent card hides the published via line and right-aligns its actions', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $post = importedCardPost($workspace, $account);
    $this->actingAs($user);

    $measure = <<<JS
        (() => {
            const footer = document.querySelector('[data-testid="post-footer-{$post->id}"]');
            const actions = document.querySelector('[data-testid="post-actions-{$post->id}"]').getBoundingClientRect();
            const via = document.querySelector('[data-testid="post-published-via-{$post->id}"]');

            return {
                viaVisible: via.getClientRects().length > 0,
                actionsAtEnd: Math.abs(footer.getBoundingClientRect().right - parseFloat(getComputedStyle(footer).paddingRight) - actions.right) <= 1,
            };
        })()
    JS;

    $page = visit(route('app.posts.index', ['tab' => 'sent']))->resize(390, 844);
    waitForImportedCardTestId($page, "post-actions-{$post->id}");

    expect($page->script($measure))->toBe(['viaVisible' => false, 'actionsAtEnd' => true]);

    $page->resize(1280, 800);
    waitForImportedCardTestId($page, "post-published-via-{$post->id}");

    expect($page->script($measure)['viaVisible'])->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('on a phone the details dialog of an imported post hides the published via line and right-aligns the view post button and menu', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $post = importedCardPost($workspace, $account);
    $this->actingAs($user);

    $measure = <<<JS
        (() => {
            const footer = document.querySelector('[data-testid="post-details-footer-{$post->id}"]');
            const menu = document.querySelector('[data-testid="post-card-menu-details-{$post->id}"]').getBoundingClientRect();
            const visible = (id) => (document.querySelector('[data-testid="' + id + '"]')?.getClientRects().length ?? 0) > 0;

            return {
                viaVisible: visible('post-details-published-via-{$post->id}'),
                viewVisible: visible('post-details-view-{$post->id}'),
                menuAtEnd: Math.abs(footer.getBoundingClientRect().right - parseFloat(getComputedStyle(footer).paddingRight) - menu.right) <= 1,
            };
        })()
    JS;

    $page = visit(route('app.posts.index', ['tab' => 'sent']))->resize(390, 844);
    waitForImportedCardTestId($page, "post-card-menu-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitForImportedCardTestId($page, "post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitForImportedCardTestId($page, "post-card-menu-details-{$post->id}");

    expect($page->script($measure))->toBe(['viaVisible' => false, 'viewVisible' => true, 'menuAtEnd' => true]);

    $page->resize(1280, 800);
    waitForImportedCardTestId($page, "post-details-view-{$post->id}");

    $desktop = $page->script($measure);
    expect($desktop['viaVisible'])->toBeTrue()
        ->and($desktop['viewVisible'])->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('a trypost sent post keeps the created by footer and shows its metrics in its details', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Published,
        'published_at' => now()->subHour(),
    ]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);
    importedCardMetrics($workspace, $account, $target);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForImportedCardTestId($page, "post-card-menu-{$post->id}");
    $page->assertSeeIn("@post-created-by-{$post->id}", $user->name)
        ->assertMissing("@post-published-via-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitForImportedCardTestId($page, "post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitForImportedCardTestId($page, "post-details-{$post->id}");

    $page->assertSeeIn("@post-details-created-by-{$post->id}", $user->name)
        ->assertPresent("@post-details-metrics-{$post->id}")
        ->assertMissing("@post-details-published-via-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('a draft shows no metrics band in its details', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $post = Post::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForImportedCardTestId($page, "post-card-menu-{$post->id}");
    $page->click("@post-card-menu-{$post->id}");
    waitForImportedCardTestId($page, "post-details-open-{$post->id}");
    $page->click("@post-details-open-{$post->id}");
    waitForImportedCardTestId($page, "post-details-{$post->id}");

    $page->assertSeeIn("@post-details-created-by-{$post->id}", $user->name)
        ->assertMissing("@post-details-metrics-{$post->id}")
        ->assertMissing("@post-details-view-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('the content type badge has an icon for stories and reels and is hidden for regular posts and TikTok', function () {
    [$user, $workspace, $account] = importedCardSetup();
    $expectations = [
        [importedCardPost($workspace, $account, ContentType::InstagramStory), 'posts.content_types.instagram_story.label'],
        [importedCardPost($workspace, $account, ContentType::InstagramReel), 'posts.content_types.instagram_reel.label'],
        [importedCardPost($workspace, $account, ContentType::InstagramFeed), null],
    ];
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]);

    foreach ([ContentType::TikTokPhoto, ContentType::TikTokVideo] as $contentType) {
        $post = Post::factory()->published()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
        ]);
        PostPlatform::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $tiktok->id,
            'platform' => Platform::TikTok,
            'content_type' => $contentType,
        ]);
        $expectations[] = [$post, null];
    }

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));

    foreach ($expectations as [$post, $label]) {
        waitForImportedCardTestId($page, "post-card-{$post->id}");

        if ($label === null) {
            $page->assertMissing("@post-content-type-{$post->id}");
        } else {
            $page->assertSeeIn("@post-content-type-{$post->id}", __($label))
                ->assertScript("document.querySelector('[data-testid=\"post-content-type-{$post->id}\"] svg') !== null", true);
        }
    }

    $page->assertNoJavaScriptErrors();
});
