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
        ->assertMissing("@post-schedule-mode-{$post->id}")
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

test('the content type badge has an icon for stories and reels and is hidden for a regular post', function (ContentType $contentType, ?string $label) {
    [$user, $workspace, $account] = importedCardSetup();
    $post = importedCardPost($workspace, $account, $contentType);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForImportedCardTestId($page, "post-card-{$post->id}");

    if ($label === null) {
        $page->assertMissing("@post-content-type-{$post->id}");
    } else {
        $page->assertSeeIn("@post-content-type-{$post->id}", __($label))
            ->assertScript("document.querySelector('[data-testid=\"post-content-type-{$post->id}\"] svg') !== null", true);
    }

    $page->assertNoJavaScriptErrors();
})->with([
    'story' => [ContentType::InstagramStory, 'posts.content_types.instagram_story.label'],
    'reel' => [ContentType::InstagramReel, 'posts.content_types.instagram_reel.label'],
    'regular post' => [ContentType::InstagramFeed, null],
]);
