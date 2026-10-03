<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function waitForMediaLightboxCondition(mixed $page, string $condition): void
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

function waitForMediaLightboxTestId(mixed $page, string $testId): void
{
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function waitForMediaLightboxGone(mixed $page): void
{
    waitForMediaLightboxCondition($page, "!document.querySelector('[data-testid=\"media-lightbox\"]')");
}

function mediaLightboxCounter(mixed $page): string
{
    return trim((string) $page->script("document.querySelector('[data-testid=\"media-lightbox-counter\"]')?.textContent ?? ''"));
}

function mediaLightboxImageLabel(mixed $page): string
{
    $source = rawurldecode((string) $page->script("document.querySelector('[data-testid=\"media-lightbox-image\"]')?.getAttribute('src') ?? ''"));

    return preg_match('/>(\d+)</', $source, $matches) === 1 ? $matches[1] : '';
}

/**
 * @return array<string, mixed>
 */
function mediaLightboxImage(int $index): array
{
    $svg = "<svg xmlns='http://www.w3.org/2000/svg' width='400' height='300'><rect width='400' height='300' fill='#3b82f6'/><text x='200' y='160' font-size='64' text-anchor='middle'>{$index}</text></svg>";

    return [
        'id' => (string) Str::uuid(),
        'path' => "medias/photo-{$index}.svg",
        'url' => 'data:image/svg+xml,'.rawurlencode($svg),
        'type' => 'image',
        'mime_type' => 'image/svg+xml',
        'original_filename' => "photo-{$index}.svg",
    ];
}

/**
 * @return array<string, mixed>
 */
function mediaLightboxVideo(): array
{
    return [
        'id' => (string) Str::uuid(),
        'path' => 'medias/clip.mp4',
        'url' => 'https://cdn.example.test/clip.mp4',
        'type' => 'video',
        'mime_type' => 'video/mp4',
        'original_filename' => 'clip.mp4',
    ];
}

/**
 * @param  list<array<string, mixed>>  $media
 * @return array{0: User, 1: Post}
 */
function mediaLightboxSetup(array $media, string $state = 'published'): array
{
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $post = Post::factory()->{$state}()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Lightbox post',
        'media' => $media,
        ...($state === 'published' ? ['published_at' => now()->subHour()] : []),
    ]);
    $target = $state === 'published' ? PostPlatform::factory()->published() : PostPlatform::factory();
    $target->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
        ...($state === 'published' ? ['published_at' => now()->subHour()] : []),
    ]);

    return [$user, $post];
}

test('a single image opens without navigation and closes on escape', function () {
    [$user, $post] = mediaLightboxSetup([mediaLightboxImage(1)], 'draft');
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-0");
    $before = $page->script('location.href');

    $page->click("@post-thumbnail-{$post->id}-0");
    waitForMediaLightboxTestId($page, 'media-lightbox-image');

    $page->assertVisible('@media-lightbox-image')
        ->assertVisible('@media-lightbox-close')
        ->assertNotPresent('@media-lightbox-previous')
        ->assertNotPresent('@media-lightbox-next')
        ->assertNotPresent('@media-lightbox-counter')
        ->assertNotPresent('@media-lightbox-thumbnails')
        ->assertNotPresent("@post-details-{$post->id}");
    expect($page->script('location.href'))->toBe($before)
        ->and(mediaLightboxImageLabel($page))->toBe('1');

    $page->keys('@media-lightbox', 'Escape');
    waitForMediaLightboxGone($page);

    $page->assertNotPresent('@media-lightbox');
    expect($page->script('location.href'))->toBe($before);
    $page->assertNoJavaScriptErrors();
});

test('five images open at the clicked one and move with the buttons and arrow keys', function () {
    [$user, $post] = mediaLightboxSetup(array_map(mediaLightboxImage(...), range(1, 5)));
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-1");

    $page->assertPresent("@post-thumbnail-more-{$post->id}")
        ->assertNotPresent("@post-thumbnail-{$post->id}-4");

    $page->click("@post-thumbnail-{$post->id}-1");
    waitForMediaLightboxTestId($page, 'media-lightbox-counter');

    expect(mediaLightboxCounter($page))->toBe('2 / 5')
        ->and(mediaLightboxImageLabel($page))->toBe('2');

    $page->click('@media-lightbox-next');
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"media-lightbox-counter\"]').textContent.trim() === '3 / 5'");
    expect(mediaLightboxCounter($page))->toBe('3 / 5');

    $page->keys('@media-lightbox', 'ArrowRight');
    $page->keys('@media-lightbox', 'ArrowRight');
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"media-lightbox-counter\"]').textContent.trim() === '5 / 5'");
    expect(mediaLightboxCounter($page))->toBe('5 / 5')
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-next\"]').disabled"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-previous\"]').disabled"))->toBeFalse();

    $page->keys('@media-lightbox', 'ArrowRight');
    expect(mediaLightboxCounter($page))->toBe('5 / 5');

    $page->click('@media-lightbox-previous');
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"media-lightbox-counter\"]').textContent.trim() === '4 / 5'");
    $page->keys('@media-lightbox', 'ArrowLeft');
    $page->keys('@media-lightbox', 'ArrowLeft');
    $page->keys('@media-lightbox', 'ArrowLeft');
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"media-lightbox-counter\"]').textContent.trim() === '1 / 5'");
    expect(mediaLightboxCounter($page))->toBe('1 / 5')
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-previous\"]').disabled"))->toBeTrue();

    $page->click('@media-lightbox-close');
    waitForMediaLightboxGone($page);

    $page->click("@post-thumbnail-more-{$post->id}");
    waitForMediaLightboxTestId($page, 'media-lightbox-counter');

    expect(mediaLightboxCounter($page))->toBe('5 / 5')
        ->and(mediaLightboxImageLabel($page))->toBe('5');
    $page->assertNotPresent("@post-details-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('the thumbnail strip jumps to the clicked item and marks it active', function () {
    [$user, $post] = mediaLightboxSetup(array_map(mediaLightboxImage(...), range(1, 5)));
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-0");

    $page->click("@post-thumbnail-{$post->id}-0");
    waitForMediaLightboxTestId($page, 'media-lightbox-thumbnails');

    $active = "[...document.querySelectorAll('[data-testid^=\"media-lightbox-thumbnail-\"]')].map((thumb) => thumb.dataset.active)";

    expect($page->script("document.querySelectorAll('[data-testid^=\"media-lightbox-thumbnail-\"]').length"))->toBe(5)
        ->and($page->script($active))->toBe(['true', 'false', 'false', 'false', 'false']);

    $page->click('@media-lightbox-thumbnail-3');
    waitForMediaLightboxCondition($page, "document.querySelector('[data-testid=\"media-lightbox-counter\"]').textContent.trim() === '4 / 5'");

    expect(mediaLightboxCounter($page))->toBe('4 / 5')
        ->and(mediaLightboxImageLabel($page))->toBe('4')
        ->and($page->script($active))->toBe(['false', 'false', 'false', 'true', 'false'])
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-thumbnail-3\"]').getAttribute('aria-current')"))->toBe('true');

    $layout = $page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const strip = rect('media-lightbox-thumbnails');
            const image = rect('media-lightbox-image');
            const counter = rect('media-lightbox-counter');
            const zoom = rect('media-lightbox-zoom-in');

            return {
                imageAboveStrip: image.bottom <= strip.top,
                zoomAboveStrip: zoom.bottom <= strip.top,
                counterOnTop: counter.bottom <= image.top,
                thumbSize: Math.round(rect('media-lightbox-thumbnail-0').width),
            };
        })()
    JS);

    expect($layout)->toBe(['imageAboveStrip' => true, 'zoomAboveStrip' => true, 'counterOnTop' => true, 'thumbSize' => 80]);
    $page->assertNoJavaScriptErrors();
});

test('a video plays in the lightbox', function () {
    [$user, $post] = mediaLightboxSetup([mediaLightboxImage(1), mediaLightboxVideo()]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-1");

    $page->click("@post-thumbnail-{$post->id}-1");
    waitForMediaLightboxTestId($page, 'media-lightbox-counter');

    expect(mediaLightboxCounter($page))->toBe('2 / 2')
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-video\"]')?.tagName"))->toBe('VIDEO')
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-video\"]').getAttribute('src')"))->toBe('https://cdn.example.test/clip.mp4')
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-video\"]').controls"))->toBeTrue();
    $page->assertNotPresent('@media-lightbox-image')
        ->assertNotPresent('@media-lightbox-zoom-in')
        ->assertNoJavaScriptErrors();
});

test('hovering a thumbnail shows the expand icon', function () {
    [$user, $post] = mediaLightboxSetup([mediaLightboxImage(1)]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-0");

    $opacity = "getComputedStyle(document.querySelector('[data-testid=\"post-thumbnail-expand-{$post->id}-0\"]')).opacity";

    expect($page->script($opacity))->toBe('0');

    $page->hover("@post-thumbnail-{$post->id}-0");
    waitForMediaLightboxCondition($page, "{$opacity} === '1'");

    expect($page->script($opacity))->toBe('1');
    $page->assertNoJavaScriptErrors();
});

test('the details dialog media strip opens the same lightbox', function () {
    [$user, $post] = mediaLightboxSetup(array_map(mediaLightboxImage(...), range(1, 3)));
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['post' => $post->id]));
    waitForMediaLightboxTestId($page, "post-details-media-item-{$post->id}-2");

    $page->click("@post-details-media-item-{$post->id}-2");
    waitForMediaLightboxTestId($page, 'media-lightbox-counter');

    expect(mediaLightboxCounter($page))->toBe('3 / 3')
        ->and(mediaLightboxImageLabel($page))->toBe('3');

    $page->keys('@media-lightbox', 'Escape');
    waitForMediaLightboxGone($page);

    $page->assertNotPresent('@media-lightbox')
        ->assertVisible("@post-details-{$post->id}")
        ->assertNoJavaScriptErrors();
});

test('the zoom buttons scale the image', function () {
    [$user, $post] = mediaLightboxSetup([mediaLightboxImage(1)]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForMediaLightboxTestId($page, "post-thumbnail-{$post->id}-0");

    $page->click("@post-thumbnail-{$post->id}-0");
    waitForMediaLightboxTestId($page, 'media-lightbox-image');

    $scale = "new DOMMatrix(getComputedStyle(document.querySelector('[data-testid=\"media-lightbox-image\"]')).transform).a";

    expect($page->script($scale))->toEqual(1)
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-zoom-out\"]').disabled"))->toBeTrue();

    $page->click('@media-lightbox-zoom-in');
    $page->click('@media-lightbox-zoom-in');
    waitForMediaLightboxCondition($page, "{$scale} === 2");

    expect($page->script($scale))->toEqual(2);

    $page->click('@media-lightbox-zoom-in');
    $page->click('@media-lightbox-zoom-in');
    waitForMediaLightboxCondition($page, "{$scale} === 3");

    expect($page->script($scale))->toEqual(3)
        ->and($page->script("document.querySelector('[data-testid=\"media-lightbox-zoom-in\"]').disabled"))->toBeTrue();

    $page->click('@media-lightbox-zoom-out');
    waitForMediaLightboxCondition($page, "{$scale} === 2.5");

    expect($page->script($scale))->toEqual(2.5);
    $page->assertVisible('@media-lightbox')
        ->assertNoJavaScriptErrors();
});
