<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

const CHANNEL_GRID_PIXEL = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function waitForChannelGridTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 300; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function channelGridBrowserUser(): User
{
    $user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

/**
 * @param  list<array<string, mixed>>  $media
 */
function channelGridBrowserPost(SocialAccount $account, string $publishedAt, ContentType $contentType, array $media): Post
{
    return Post::factory()->forAccount($account, $contentType)->published()->create([
        'published_at' => $publishedAt,
        'media' => $media,
    ]);
}

/**
 * @return array<string, mixed>
 */
function channelGridImage(): array
{
    return ['id' => (string) str()->uuid(), 'path' => 'media/pixel.png', 'url' => CHANNEL_GRID_PIXEL, 'type' => 'image', 'mime_type' => 'image/png'];
}

beforeEach(function () {
    $this->user = channelGridBrowserUser();
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->user->current_workspace_id,
        'platform' => Platform::Instagram,
    ]);
});

test('an instagram channel offers the grid view and the grid lays posts out in three columns', function () {
    $reel = channelGridBrowserPost($this->instagram, '2026-09-20 10:00:00', ContentType::InstagramReel, [
        ['id' => (string) str()->uuid(), 'path' => 'media/reel.mp4', 'url' => 'https://cdn.test/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'],
    ]);
    $carousel = channelGridBrowserPost($this->instagram, '2026-09-10 10:00:00', ContentType::InstagramFeed, [channelGridImage(), channelGridImage()]);
    $single = channelGridBrowserPost($this->instagram, '2026-09-01 10:00:00', ContentType::InstagramFeed, [channelGridImage()]);
    $story = channelGridBrowserPost($this->instagram, '2026-09-25 10:00:00', ContentType::InstagramStory, [channelGridImage()]);

    $this->actingAs($this->user);

    $page = visit(route('app.channels.publish', $this->instagram));
    waitForChannelGridTestId($page, 'schedule-view-grid');

    $page->assertVisible('@schedule-view-grid')
        ->click('@schedule-view-grid');

    waitForChannelGridTestId($page, 'channel-grid');

    $page->assertPathIs(route('app.channels.grid', $this->instagram, false))
        ->assertVisible("@grid-tile-{$reel->id}")
        ->assertVisible("@grid-tile-{$carousel->id}")
        ->assertVisible("@grid-tile-{$single->id}")
        ->assertMissing("@grid-tile-{$story->id}")
        ->assertVisible("@grid-tile-reel-{$reel->id}")
        ->assertVisible("@grid-tile-carousel-{$carousel->id}")
        ->assertMissing("@grid-tile-reel-{$single->id}")
        ->assertMissing("@grid-tile-carousel-{$single->id}");

    $columns = $page->script(<<<'JS'
        getComputedStyle(document.querySelector('[data-testid="channel-grid"]')).gridTemplateColumns.split(' ').length
    JS);
    expect($columns)->toBe(3);

    $tops = $page->script(<<<JS
        ['{$reel->id}', '{$carousel->id}', '{$single->id}']
            .map((id) => Math.round(document.querySelector(`[data-testid="grid-tile-\${id}"]`).getBoundingClientRect().top))
    JS);
    expect(array_unique($tops))->toHaveCount(1);

    $page->hover("@grid-tile-{$reel->id}");
    waitForChannelGridTestId($page, "grid-tile-tooltip-{$reel->id}");

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"grid-tile-tooltip-{$reel->id}\"] [role=\"tooltip\"]').textContent")))
        ->toBe('This post was sent September 20, 2026 at 10:00 AM');

    $page->assertNoJavaScriptErrors();
});

test('the grid sits under the shared channel header, which stays put while the grid scrolls', function () {
    foreach (range(1, 9) as $day) {
        channelGridBrowserPost($this->instagram, "2026-09-0{$day} 10:00:00", ContentType::InstagramFeed, [channelGridImage()]);
    }

    $this->actingAs($this->user);

    $page = visit(route('app.channels.grid', $this->instagram));
    waitForChannelGridTestId($page, 'channel-grid');

    $page->assertVisible('@header-title')
        ->assertVisible('@channel-grid-divider')
        ->assertMissing('@posts-tabs')
        ->assertMissing('@publish-filters');

    $before = $page->script('Math.round(document.querySelector(\'[data-testid="header-title"]\').getBoundingClientRect().top)');
    $scrolled = $page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="channel-grid-scroll"]');
            scroller.scrollTop = 600;
            return scroller.scrollTop;
        })()
    JS);
    $after = $page->script('Math.round(document.querySelector(\'[data-testid="header-title"]\').getBoundingClientRect().top)');

    expect($scrolled)->toBeGreaterThan(0)
        ->and($after)->toBe($before);

    $page->hover('@schedule-view-grid');
    waitForChannelGridTestId($page, 'schedule-view-grid-note');

    expect(trim((string) $page->script('document.querySelector(\'[data-testid="schedule-view-grid-note"] [role="tooltip"]\').textContent')))
        ->toBe('This is an approximation of your post grid. It may differ on other devices, and posts published directly on Instagram update when we sync them.');

    $page->assertNoJavaScriptErrors();
});

test('the grid keeps the schedule view switch and the new post button', function () {
    channelGridBrowserPost($this->instagram, '2026-09-01 10:00:00', ContentType::InstagramFeed, [channelGridImage()]);

    $this->actingAs($this->user);

    $page = visit(route('app.channels.grid', $this->instagram));
    waitForChannelGridTestId($page, 'schedule-view-grid');

    $page->assertAttribute('@schedule-view-grid', 'aria-current', 'page')
        ->assertAttributeMissing('@schedule-view-list', 'aria-current')
        ->assertVisible('@schedule-view-calendar')
        ->click('@posts-new-post');

    waitForChannelGridTestId($page, 'post-composer-dialog');
    waitForChannelGridTestId($page, "composer-account-{$this->instagram->id}");

    $page->assertVisible('@post-composer-dialog')
        ->assertVisible("@composer-account-{$this->instagram->id}")
        ->assertPathIs(route('app.channels.grid', $this->instagram, false));

    $page = visit(route('app.channels.grid', $this->instagram));
    waitForChannelGridTestId($page, 'schedule-view-list');
    $page->click('@schedule-view-list');
    waitForChannelGridTestId($page, 'publish-page');

    $page->assertPathIs(route('app.channels.publish', $this->instagram, false))
        ->assertAttribute('@schedule-view-list', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('clicking a carousel tile opens its media in the lightbox', function () {
    $carousel = channelGridBrowserPost($this->instagram, '2026-09-10 10:00:00', ContentType::InstagramFeed, [channelGridImage(), channelGridImage()]);

    $this->actingAs($this->user);

    $page = visit(route('app.channels.grid', $this->instagram));
    waitForChannelGridTestId($page, "grid-tile-{$carousel->id}");

    $page->click("@grid-tile-{$carousel->id}");
    waitForChannelGridTestId($page, 'media-lightbox-counter');

    $page->assertVisible('@media-lightbox')
        ->assertVisible('@media-lightbox-image')
        ->assertSeeIn('@media-lightbox-counter', '1 / 2')
        ->click('@media-lightbox-next')
        ->assertSeeIn('@media-lightbox-counter', '2 / 2')
        ->assertNoJavaScriptErrors();
});

test('the grid tooltip shows the sent time in the user zone, not the browser zone', function () {
    $this->user->update(['timezone' => 'Asia/Tokyo']);
    $reel = channelGridBrowserPost($this->instagram, '2026-09-20 10:00:00', ContentType::InstagramReel, [
        ['id' => (string) str()->uuid(), 'path' => 'media/reel.mp4', 'url' => 'https://cdn.test/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'],
    ]);
    $this->actingAs($this->user);

    $page = visit(route('app.channels.grid', $this->instagram));
    waitForChannelGridTestId($page, "grid-tile-{$reel->id}");
    $page->hover("@grid-tile-{$reel->id}");
    waitForChannelGridTestId($page, "grid-tile-tooltip-{$reel->id}");

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"grid-tile-tooltip-{$reel->id}\"] [role=\"tooltip\"]').textContent")))
        ->toBe('This post was sent September 20, 2026 at 7:00 PM');
    $page->assertNoJavaScriptErrors();
});
