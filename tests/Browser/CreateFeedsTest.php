<?php

declare(strict_types=1);

use App\Jobs\RssFeed\FetchRssFeed;
use App\Models\Idea;
use App\Models\Media;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function waitForCreateFeedsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateFeedsCondition(mixed $page, string $condition): void
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

/**
 * Dialogs animate in; a click that lands before the animation settles is swallowed.
 */
function waitForCreateFeedsDialog(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const dialog = document.querySelector('[data-testid="{$testId}"]');
                if (dialog?.getAttribute('data-state') === 'open'
                    && dialog.getAnimations().every((animation) => animation.playState !== 'running')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateFeedsDatabase(mixed $page, Closure $condition): void
{
    for ($attempt = 0; $attempt < 50 && ! $condition(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

function createFeedsToastCount(mixed $page): int
{
    return $page->script("document.querySelectorAll('[data-sonner-toast]').length");
}

/**
 * @return array{0: User, 1: Workspace}
 */
function createFeedsSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace];
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createFeedsFeed(Workspace $workspace, array $attributes = []): RssFeed
{
    return RssFeed::factory()->create([
        'workspace_id' => $workspace->id,
        'icon_url' => null,
        'last_succeeded_at' => now()->subHour(),
        'last_fetched_at' => now()->subHour(),
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function createFeedsItem(RssFeed $feed, array $attributes = []): RssFeedItem
{
    return RssFeedItem::factory()->for($feed, 'feed')->create([
        'image_url' => null,
        ...$attributes,
    ]);
}

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake();
});

test('all feeds lists every item newest first and ends with the caught up line', function () {
    [$user, $workspace] = createFeedsSetup();
    $first = createFeedsFeed($workspace, ['title' => 'First source']);
    $second = createFeedsFeed($workspace, ['title' => 'Second source']);
    $older = createFeedsItem($first, ['published_at' => now()->subDays(2)]);
    $newest = createFeedsItem($second, ['published_at' => now()->subHour()]);
    $middle = createFeedsItem($first, ['published_at' => now()->subDay()]);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feed-item-{$newest->id}");
    waitForCreateFeedsTestId($page, 'feeds-caught-up');

    $page->assertAttribute('@create-tab-feeds', 'aria-current', 'page')
        ->assertAttribute('@feeds-chip-all', 'aria-current', 'page')
        ->assertVisible('@feeds-last-refreshed')
        ->assertVisible('@feeds-caught-up')
        ->assertMissing('@feeds-scope-menu')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"create-tabs\"]').lastElementChild.dataset.testid"))->toBe('create-tab-feeds')
        ->and($page->script("[...document.querySelectorAll('[data-testid^=\"feed-item-\"]')].filter((el) => el.tagName === 'ARTICLE').map((el) => el.dataset.testid)"))
        ->toBe(["feed-item-{$newest->id}", "feed-item-{$middle->id}", "feed-item-{$older->id}"]);
});

test('a feed chip opens that feed with only its items', function () {
    [$user, $workspace] = createFeedsSetup();
    $first = createFeedsFeed($workspace);
    $second = createFeedsFeed($workspace);
    $mine = createFeedsItem($first);
    $other = createFeedsItem($second);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feeds-chip-feed-{$first->id}");
    $page->click("@feeds-chip-feed-{$first->id}");
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');

    expect($page->script('window.location.pathname'))->toBe(route('app.create.feeds.show', $first, false));

    $page->assertVisible("@feed-item-{$mine->id}")
        ->assertMissing("@feed-item-{$other->id}")
        ->assertVisible('@feeds-last-refreshed')
        ->assertAttribute("@feeds-chip-feed-{$first->id}", 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('hovering an item reveals its actions and create post opens the composer with the imported image', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response((string) file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace);
    $item = createFeedsItem($feed, ['title' => 'Launch notes', 'url' => 'https://93.184.216.34/launch']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feed-item-{$item->id}");
    $item->update(['image_url' => 'https://93.184.216.34/image.png']);

    $page->hover("@feed-item-{$item->id}")
        ->assertVisible("@feed-item-open-{$item->id}")
        ->assertAttribute("@feed-item-open-{$item->id}", 'aria-label', 'Open article: Launch notes')
        ->assertAttribute("@feed-item-open-{$item->id}", 'rel', 'noopener noreferrer')
        ->assertVisible("@feed-item-save-idea-{$item->id}");

    $page->click("@feed-item-create-post-{$item->id}");
    waitForCreateFeedsTestId($page, 'composer-base-content');
    waitForCreateFeedsTestId($page, 'composer-media-item');

    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', "Launch notes\n\nhttps://93.184.216.34/launch")
        ->assertVisible('@composer-media-item')
        ->assertNoJavaScriptErrors();

    expect(Media::query()->where('rss_feed_item_id', $item->id)->count())->toBe(1)
        ->and(Media::query()->count())->toBe(1);
});

test('create post opens the composer without media when the image cannot be imported', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response('nope', 500)]);
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace);
    $item = createFeedsItem($feed, ['title' => 'No image', 'url' => 'https://93.184.216.34/none']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feed-item-{$item->id}");
    $item->update(['image_url' => 'https://93.184.216.34/image.png']);

    $page->hover("@feed-item-{$item->id}")->click("@feed-item-create-post-{$item->id}");
    waitForCreateFeedsTestId($page, 'composer-base-content');

    $page->assertValue('@composer-base-content', "No image\n\nhttps://93.184.216.34/none")
        ->assertMissing('@composer-media-item')
        ->assertNoJavaScriptErrors();

    expect(Media::query()->count())->toBe(0);
});

test('save as idea creates an unassigned idea with the imported image and shows saved without a toast', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response((string) file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace);
    $item = createFeedsItem($feed, ['title' => 'Worth saving']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feed-item-{$item->id}");
    $item->update(['image_url' => 'https://93.184.216.34/image.png']);

    $page->hover("@feed-item-{$item->id}")->click("@feed-item-save-idea-{$item->id}");
    waitForCreateFeedsTestId($page, "feed-item-saved-{$item->id}");

    $page->assertVisible("@feed-item-saved-{$item->id}")
        ->assertMissing("@feed-item-save-idea-{$item->id}")
        ->assertNoJavaScriptErrors();

    $idea = Idea::query()->sole();
    $image = $item->image()->sole();
    $copy = $idea->ownedMedia()->sole();

    expect(createFeedsToastCount($page))->toBe(0)
        ->and($idea->title)->toBe('Worth saving')
        ->and($idea->idea_stage_id)->toBeNull()
        ->and(data_get($copy->meta, 'copied_from'))->toBe($image->id)
        ->and(collect($idea->media)->pluck('id')->all())->toBe([$copy->id]);

    $ideas = visit(route('app.create.ideas.index'));
    waitForCreateFeedsTestId($ideas, "idea-card-{$idea->id}");

    $ideas->assertVisible("@idea-card-{$idea->id}")->assertNoJavaScriptErrors();
});

test('the scope menu renames in a dialog, moves into a collection and back, and deletes with the keyword', function () {
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace, ['title' => 'Original']);
    $other = createFeedsFeed($workspace);
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Reading']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.show', $feed));
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');

    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-rename');
    $page->click('@feeds-rename');
    waitForCreateFeedsDialog($page, 'feeds-rename-dialog');

    expect($page->script("document.querySelector('[data-testid=\"feeds-rename-dialog-input\"]').value"))->toBe('Original');

    $page->type('@feeds-rename-dialog-input', 'Renamed')->click('@feeds-rename-dialog-submit');
    waitForCreateFeedsCondition($page, "document.querySelector('[data-testid=\"feeds-chip-feed-{$feed->id}\"]')?.textContent.includes('Renamed') && !document.querySelector('[data-testid=\"feeds-rename-dialog\"]')");

    $page->assertSeeIn("@feeds-chip-feed-{$feed->id}", 'Renamed');
    expect($feed->refresh()->custom_title)->toBe('Renamed');

    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-rename');
    $page->click('@feeds-rename');
    waitForCreateFeedsDialog($page, 'feeds-rename-dialog');
    $page->type('@feeds-rename-dialog-input', 'Discarded')->click('@feeds-rename-dialog-cancel');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-rename-dialog\"]')");

    $page->assertSeeIn('@feeds-scope-name', 'Renamed');
    expect($feed->refresh()->custom_title)->toBe('Renamed');

    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-move-to');
    $page->click('@feeds-move-to');
    waitForCreateFeedsTestId($page, "feeds-move-to-{$collection->id}");
    $page->click("@feeds-move-to-{$collection->id}");
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-chip-feed-{$feed->id}\"]')");

    expect($feed->refresh()->rss_feed_collection_id)->toBe($collection->id)
        ->and($page->script("!!document.querySelector('[data-testid=\"feeds-collection-chips\"]')"))->toBeFalse();

    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-move-to');
    $page->click('@feeds-move-to');
    waitForCreateFeedsTestId($page, 'feeds-move-to-none');
    $page->click('@feeds-move-to-none');
    waitForCreateFeedsCondition($page, "!!document.querySelector('[data-testid=\"feeds-chips\"] [data-testid=\"feeds-chip-feed-{$feed->id}\"]')");

    expect($feed->refresh()->rss_feed_collection_id)->toBeNull();

    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-delete');
    $page->click('@feeds-delete');
    waitForCreateFeedsDialog($page, 'confirm-delete-modal');

    $page->assertMissing('@confirm-delete-input')
        ->click('@confirm-delete-action');
    waitForCreateFeedsCondition($page, "window.location.pathname === '".route('app.create.feeds.index', [], false)."'");

    $page->assertMissing("@feeds-chip-feed-{$feed->id}")
        ->assertVisible("@feeds-chip-feed-{$other->id}")
        ->assertNoJavaScriptErrors();

    expect(RssFeed::query()->whereKey($feed->id)->exists())->toBeFalse()
        ->and(createFeedsToastCount($page))->toBe(0);
});

test('the new menu creates a collection in a dialog and cancel adds nothing', function () {
    [$user, $workspace] = createFeedsSetup();
    createFeedsFeed($workspace);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-new-menu');

    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-new-collection');

    $page->assertVisible('@feeds-explore')
        ->assertVisible('@feeds-new-collection');
    expect($page->script("[...document.querySelectorAll('[role=\"menuitem\"]')].length"))->toBe(2);

    $page->click('@feeds-new-collection');
    waitForCreateFeedsDialog($page, 'feeds-new-collection-dialog');
    $page->assertDisabled('@feeds-new-collection-dialog-submit')
        ->type('@feeds-new-collection-dialog-input', 'Research')
        ->click('@feeds-new-collection-dialog-submit');
    waitForCreateFeedsDatabase($page, fn (): bool => RssFeedCollection::query()->where('workspace_id', $workspace->id)->exists());

    $collection = RssFeedCollection::query()->where('workspace_id', $workspace->id)->sole();
    waitForCreateFeedsTestId($page, "feeds-chip-collection-{$collection->id}");

    $page->assertSeeIn("@feeds-chip-collection-{$collection->id}", 'Research');

    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-new-collection');
    $page->click('@feeds-new-collection');
    waitForCreateFeedsDialog($page, 'feeds-new-collection-dialog');
    $page->type('@feeds-new-collection-dialog-input', 'Nope')->click('@feeds-new-collection-dialog-cancel');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-new-collection-dialog\"]')");

    $page->assertNoJavaScriptErrors();
    expect(RssFeedCollection::query()->count())->toBe(1)
        ->and(createFeedsToastCount($page))->toBe(0);
});

test('the collection menu lists its feeds, renames in a dialog and navigates to a feed', function () {
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Tech']);
    $inside = createFeedsFeed($workspace, ['title' => 'Inside Feed', 'rss_feed_collection_id' => $collection->id]);
    $outside = createFeedsFeed($workspace, ['title' => 'Outside Feed']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.collections.show', $collection));
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');
    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-collection-menu-feeds');

    $page->assertSeeIn('@feeds-collection-menu-feeds', 'Inside Feed')
        ->assertMissing("@feeds-collection-menu-feed-{$outside->id}")
        ->assertVisible('@feeds-add-to-collection');

    $page->click("@feeds-collection-menu-feed-{$inside->id}");
    waitForCreateFeedsCondition($page, "window.location.pathname === '".route('app.create.feeds.show', $inside, false)."'");

    $page = visit(route('app.create.feeds.collections.show', $collection));
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');
    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-rename');
    $page->click('@feeds-rename');
    waitForCreateFeedsDialog($page, 'feeds-rename-dialog');
    $page->type('@feeds-rename-dialog-input', 'Technology')->click('@feeds-rename-dialog-submit');
    waitForCreateFeedsDatabase($page, fn (): bool => $collection->refresh()->name === 'Technology');

    expect($collection->refresh()->name)->toBe('Technology');
    $page->assertNoJavaScriptErrors();
});

test('a collection chip opens a dropdown of its feeds and the label opens the collection', function () {
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Tech']);
    $inside = createFeedsFeed($workspace, ['title' => 'Inside Feed', 'rss_feed_collection_id' => $collection->id]);
    $outside = createFeedsFeed($workspace, ['title' => 'Outside Feed']);
    $this->actingAs($user);

    $loose = createFeedsFeed($workspace, ['title' => 'Loose Feed']);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, "feeds-collection-menu-{$collection->id}");

    expect($page->script("[...document.querySelectorAll('[data-testid=\"feeds-chips\"] > [data-testid]')].map((chip) => chip.dataset.testid)"))
        ->toBe(['feeds-chip-all', "feeds-chip-feed-{$outside->id}", "feeds-chip-feed-{$loose->id}", "feeds-collection-chip-{$collection->id}"]);
    $page->click("@feeds-collection-menu-{$collection->id}");
    waitForCreateFeedsTestId($page, "feeds-collection-menu-item-{$inside->id}");

    $page->assertVisible("@feeds-collection-menu-item-{$inside->id}")
        ->assertMissing("@feeds-collection-menu-item-{$outside->id}");

    $page->click("@feeds-collection-menu-item-{$inside->id}");
    waitForCreateFeedsCondition($page, "window.location.pathname === '".route('app.create.feeds.show', $inside, false)."'");
    waitForCreateFeedsTestId($page, "feeds-chip-collection-{$collection->id}");

    $page->click("@feeds-chip-collection-{$collection->id}");
    waitForCreateFeedsCondition($page, "window.location.pathname === '".route('app.create.feeds.collections.show', $collection, false)."'");

    $page->assertNoJavaScriptErrors();
});

test('add feed from the collection menu lands the feed in that collection', function () {
    Queue::fake();
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Into Collection</title><link>http://93.184.216.34/</link>
        <item><title>A post</title><link>http://93.184.216.34/a</link><guid>a-1</guid></item>
        </channel></rss>
        XML, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id]);
    createFeedsFeed($workspace, ['rss_feed_collection_id' => $collection->id]);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.collections.show', $collection));
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');
    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-add-to-collection');
    $page->click('@feeds-add-to-collection');
    waitForCreateFeedsTestId($page, 'feeds-collection-add-feed');
    $page->click('@feeds-collection-add-feed');
    waitForCreateFeedsDialog($page, 'feeds-add-dialog');
    $page->type('@feeds-add-url', 'http://93.184.216.34/feed.xml')->click('@feeds-add-submit');
    waitForCreateFeedsDatabase($page, fn (): bool => RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->exists());

    expect(RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->sole()->rss_feed_collection_id)->toBe($collection->id);
    $page->assertNoJavaScriptErrors();
});

test('explore from the collection menu adds the directory feed into that collection', function () {
    Queue::fake();
    config()->set('trypost.rss_feeds.directory.favorites', [['name' => 'Fresh Blog', 'url' => 'http://93.184.216.34/feed.xml']]);
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Fresh Blog</title><link>http://93.184.216.34/</link>
        <item><title>Fresh post</title><link>http://93.184.216.34/fresh</link><guid>fresh-1</guid></item>
        </channel></rss>
        XML, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id]);
    createFeedsFeed($workspace, ['rss_feed_collection_id' => $collection->id]);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.collections.show', $collection));
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');
    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-add-to-collection');
    $page->click('@feeds-add-to-collection');
    waitForCreateFeedsTestId($page, 'feeds-collection-explore');
    $page->click('@feeds-collection-explore');
    waitForCreateFeedsDialog($page, 'feeds-explore-dialog');
    $page->click('@feeds-explore-add-0');
    waitForCreateFeedsTestId($page, 'feeds-explore-remove-0');

    expect(RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->sole()->rss_feed_collection_id)->toBe($collection->id);
    $page->assertNoJavaScriptErrors();
});

test('the add dialog adds a feed, and shows blocked and duplicate errors inline', function () {
    Queue::fake();
    $rss = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Fresh Blog</title><link>http://93.184.216.34/</link>
        <item><title>Fresh post</title><link>http://93.184.216.34/fresh</link><guid>fresh-1</guid><description>Hello</description></item>
        </channel></rss>
        XML;
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response($rss, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    createFeedsFeed($workspace, ['url' => 'https://existing.example.com/feed']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-new');
    $page->click('@feeds-new');
    waitForCreateFeedsDialog($page, 'feeds-add-dialog');

    $page->assertVisible('@feeds-add-url')
        ->assertDisabled('@feeds-add-submit');
    expect($page->script("document.querySelectorAll('[data-testid=\"feeds-add-dialog\"] input').length"))->toBe(1)
        ->and($page->script("document.querySelector('[data-testid=\"feeds-add-cancel\"]').compareDocumentPosition(document.querySelector('[data-testid=\"feeds-add-submit\"]')) & Node.DOCUMENT_POSITION_FOLLOWING"))->toBeGreaterThan(0);

    $page->type('@feeds-add-url', 'http://127.0.0.1/feed.xml')->click('@feeds-add-submit');
    waitForCreateFeedsTestId($page, 'feeds-add-error');
    $page->assertSeeIn('@feeds-add-error', __('create.feeds.errors.blocked_url'));

    $page->clear('@feeds-add-url')->type('@feeds-add-url', 'https://existing.example.com/feed/')->click('@feeds-add-submit');
    waitForCreateFeedsCondition($page, "document.querySelector('[data-testid=\"feeds-add-error\"]')?.textContent.includes(".json_encode(__('create.feeds.errors.already_added')).')');
    $page->assertSeeIn('@feeds-add-error', __('create.feeds.errors.already_added'));

    $page->clear('@feeds-add-url')->type('@feeds-add-url', 'http://93.184.216.34/feed.xml')->click('@feeds-add-submit');
    waitForCreateFeedsDatabase($page, fn (): bool => RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->exists());

    $feed = RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->sole();
    waitForCreateFeedsCondition($page, "window.location.pathname === '".route('app.create.feeds.show', $feed, false)."'");
    waitForCreateFeedsTestId($page, "feeds-chip-feed-{$feed->id}");

    $page->assertMissing('@feeds-add-dialog')
        ->assertSeeIn('@feeds-scope-name', 'Fresh Blog')
        ->assertNoJavaScriptErrors();
    expect(createFeedsToastCount($page))->toBe(0);
});

test('explore switches categories, adds a directory feed and offers the trash for one already added', function () {
    Queue::fake();
    config()->set('trypost.rss_feeds.directory.favorites', [['name' => 'Fresh Blog', 'url' => 'http://93.184.216.34/feed.xml']]);
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Fresh Blog</title><link>http://93.184.216.34/</link>
        <item><title>Fresh post</title><link>http://93.184.216.34/fresh</link><guid>fresh-1</guid></item>
        </channel></rss>
        XML, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    $verge = createFeedsFeed($workspace, ['url' => 'https://www.theverge.com/rss/index.xml']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-new-menu');
    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-explore');
    $page->click('@feeds-explore');
    waitForCreateFeedsDialog($page, 'feeds-explore-dialog');

    $page->assertAttribute('@feeds-explore-category-favorites', 'aria-selected', 'true')
        ->assertVisible('@feeds-explore-grid-favorites');

    $page->click('@feeds-explore-add-0');
    waitForCreateFeedsTestId($page, 'feeds-explore-remove-0');

    $page->assertVisible('@feeds-explore-dialog')->assertMissing('@feeds-explore-add-0');
    expect(RssFeed::query()->where('workspace_id', $workspace->id)->where('url', 'http://93.184.216.34/feed.xml')->exists())->toBeTrue()
        ->and(createFeedsToastCount($page))->toBe(0);

    $page->click('@feeds-explore-category-tech');
    waitForCreateFeedsTestId($page, 'feeds-explore-grid-tech');

    $page->assertVisible('@feeds-explore-remove-1')
        ->assertMissing('@feeds-explore-add-1')
        ->assertVisible('@feeds-explore-add-0');

    $page->click('@feeds-explore-remove-1');
    waitForCreateFeedsDialog($page, 'confirm-delete-modal');
    $page->assertMissing('@confirm-delete-input')->click('@confirm-delete-action');
    waitForCreateFeedsDatabase($page, fn (): bool => ! RssFeed::query()->whereKey($verge->id)->exists());
    waitForCreateFeedsTestId($page, 'feeds-explore-add-1');

    $page->assertVisible('@feeds-explore-dialog')
        ->assertVisible('@feeds-explore-add-1')
        ->assertNoJavaScriptErrors();
    expect(RssFeed::query()->whereKey($verge->id)->exists())->toBeFalse();
});

test('refresh inside the cooldown keeps the header and shows no toast', function () {
    Queue::fake();
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace, ['last_fetched_at' => now()->subMinute(), 'last_succeeded_at' => now()->subMinute()]);
    createFeedsItem($feed);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-refresh');
    $page->click('@feeds-refresh');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-refresh\"]').disabled");

    $page->assertVisible('@feeds-last-refreshed')->assertNoJavaScriptErrors();
    expect(createFeedsToastCount($page))->toBe(0)
        ->and($feed->refresh()->refresh_requested_at)->toBeNull();
    Queue::assertNotPushed(FetchRssFeed::class);
});

test('explore opened from a feed keeps that feed url, stays there after a directory add and closes in place', function () {
    Queue::fake();
    config()->set('trypost.rss_feeds.directory.favorites', [['name' => 'Fresh Blog', 'url' => 'http://93.184.216.34/feed.xml']]);
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Fresh Blog</title><link>http://93.184.216.34/</link>
        <item><title>Fresh post</title><link>http://93.184.216.34/fresh</link><guid>fresh-1</guid></item>
        </channel></rss>
        XML, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace, ['title' => 'Scoped source']);
    createFeedsItem($feed);
    $this->actingAs($user);

    $path = route('app.create.feeds.show', $feed, false);
    $page = visit(route('app.create.feeds.show', $feed));
    waitForCreateFeedsTestId($page, 'feeds-new-menu');
    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-explore');
    $page->click('@feeds-explore');
    waitForCreateFeedsDialog($page, 'feeds-explore-dialog');

    expect($page->script('window.location.pathname'))->toBe($path)
        ->and($page->script('window.location.search'))->toBe('');

    $page->click('@feeds-explore-add-0');
    waitForCreateFeedsTestId($page, 'feeds-explore-remove-0');

    expect($page->script('window.location.pathname'))->toBe($path);
    $page->assertSeeIn('@feeds-scope-name', 'Scoped source');

    $page->keys('@feeds-explore-dialog', 'Escape');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-explore-dialog\"]')");

    expect($page->script('window.location.pathname'))->toBe($path)
        ->and($page->script('window.location.search'))->toBe('');
    $page->assertSeeIn('@feeds-scope-name', 'Scoped source')->assertNoJavaScriptErrors();
});

test('the add dialog opened from a collection keeps the collection url', function () {
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id]);
    createFeedsFeed($workspace, ['rss_feed_collection_id' => $collection->id]);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.collections.show', $collection));
    waitForCreateFeedsTestId($page, 'feeds-new');
    $page->click('@feeds-new');
    waitForCreateFeedsDialog($page, 'feeds-add-dialog');

    expect($page->script('window.location.pathname'))->toBe(route('app.create.feeds.collections.show', $collection, false))
        ->and($page->script('window.location.search'))->toBe('');

    $page->click('@feeds-add-cancel');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-add-dialog\"]')");

    expect($page->script('window.location.search'))->toBe('');
    $page->assertNoJavaScriptErrors();
});

test('explore category tabs are a complete tabs pattern with arrow key navigation', function () {
    [$user] = createFeedsSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    openCreateFeedsExploreFromSplitButton($page);
    waitForCreateFeedsTestId($page, 'feeds-explore-category-favorites');

    $page->assertAttribute('@feeds-explore-category-favorites', 'tabindex', '0')
        ->assertAttribute('@feeds-explore-grid-favorites', 'role', 'tabpanel')
        ->assertAttribute('@feeds-explore-grid-favorites', 'aria-labelledby', 'feeds-explore-tab-favorites');

    $page->script("document.querySelector('[data-testid=\"feeds-explore-category-favorites\"]').focus()");
    $page->keys('@feeds-explore-category-favorites', 'ArrowRight');
    waitForCreateFeedsCondition($page, "document.querySelector('[role=\"tab\"][aria-selected=\"true\"]')?.id !== 'feeds-explore-tab-favorites'");

    expect($page->script("document.activeElement.getAttribute('role')"))->toBe('tab')
        ->and($page->script("document.activeElement.getAttribute('aria-selected')"))->toBe('true')
        ->and($page->script("document.activeElement.getAttribute('tabindex')"))->toBe('0')
        ->and($page->script("document.querySelector('[role=\"tabpanel\"]').getAttribute('aria-labelledby') === document.activeElement.id"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the feeds page fits a 390px viewport with scrolling chips, a usable split button and reachable item actions', function () {
    [$user, $workspace] = createFeedsSetup();
    $first = createFeedsFeed($workspace, ['title' => 'A rather long feed title number one']);
    foreach (range(2, 6) as $number) {
        createFeedsFeed($workspace, ['title' => "A rather long feed title number {$number}"]);
    }
    $item = createFeedsItem($first, ['title' => 'Launch notes']);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'))->resize(390, 844);
    waitForCreateFeedsTestId($page, "feed-item-{$item->id}");

    expect($page->script('document.documentElement.scrollWidth - window.innerWidth'))->toBeLessThanOrEqual(2)
        ->and($page->script("(() => { const nav = document.querySelector('[data-testid=\"feeds-chips\"]'); return nav.scrollWidth > nav.clientWidth && getComputedStyle(nav).overflowX === 'auto'; })()"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-testid=\"feeds-new\"]').getBoundingClientRect().width"))->toBeLessThanOrEqual(40)
        ->and($page->script("(() => { const r = document.querySelector('[data-testid=\"feeds-new-menu\"]').getBoundingClientRect(); return r.width > 0 && r.right <= window.innerWidth; })()"))->toBeTrue();

    $page->assertVisible("@feed-item-create-post-{$item->id}")
        ->assertVisible("@feed-item-save-idea-{$item->id}");
    expect($page->script("(() => { const r = document.querySelector('[data-testid=\"feed-item-save-idea-{$item->id}\"]').getBoundingClientRect(); return r.right <= window.innerWidth && r.left >= 0; })()"))->toBeTrue();

    $page->click('@feeds-new');
    waitForCreateFeedsDialog($page, 'feeds-add-dialog');
    $page->assertVisible('@feeds-add-url')->assertNoJavaScriptErrors();
});

function openCreateFeedsExploreFromSplitButton(mixed $page): void
{
    waitForCreateFeedsTestId($page, 'feeds-new-menu');
    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-explore');
    $page->click('@feeds-explore');
    waitForCreateFeedsDialog($page, 'feeds-explore-dialog');
}

function openCreateFeedsExploreFromCollectionMenu(mixed $page): void
{
    waitForCreateFeedsTestId($page, 'feeds-scope-menu');
    $page->click('@feeds-scope-menu');
    waitForCreateFeedsTestId($page, 'feeds-add-to-collection');
    $page->click('@feeds-add-to-collection');
    waitForCreateFeedsTestId($page, 'feeds-collection-explore');
    $page->click('@feeds-collection-explore');
    waitForCreateFeedsDialog($page, 'feeds-explore-dialog');
}

function assertCreateFeedsExploreClosed(mixed $page, string $path): void
{
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-explore-dialog\"]')");

    $page->assertMissing('@feeds-explore-dialog');
    expect($page->script('window.location.pathname'))->toBe($path)
        ->and($page->script('window.location.search'))->toBe('');
}

test('explore closes with the close button, escape and an outside click wherever it was opened', function () {
    Queue::fake();
    config()->set('trypost.rss_feeds.directory.favorites', [['name' => 'Fresh Blog', 'url' => 'http://93.184.216.34/feed.xml']]);
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Fresh Blog</title><link>http://93.184.216.34/</link>
        <item><title>Fresh post</title><link>http://93.184.216.34/fresh</link><guid>fresh-1</guid></item>
        </channel></rss>
        XML, 200, ['Content-Type' => 'application/rss+xml'])]);
    [$user, $workspace] = createFeedsSetup();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id]);
    createFeedsFeed($workspace, ['rss_feed_collection_id' => $collection->id]);
    $this->actingAs($user);

    $path = route('app.create.feeds.collections.show', $collection, false);
    $page = visit(route('app.create.feeds.collections.show', $collection));

    openCreateFeedsExploreFromSplitButton($page);
    $page->click('@feeds-explore-category-tech');
    waitForCreateFeedsTestId($page, 'feeds-explore-grid-tech');
    $page->click('@dialog-close');
    assertCreateFeedsExploreClosed($page, $path);

    openCreateFeedsExploreFromCollectionMenu($page);
    $page->click('@feeds-explore-add-0');
    waitForCreateFeedsTestId($page, 'feeds-explore-remove-0');
    $page->click('@feeds-explore-category-tech');
    waitForCreateFeedsTestId($page, 'feeds-explore-grid-tech');
    $page->keys('@feeds-explore-dialog', 'Escape');
    assertCreateFeedsExploreClosed($page, $path);

    openCreateFeedsExploreFromSplitButton($page);
    $page->script("document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))");
    assertCreateFeedsExploreClosed($page, $path);

    openCreateFeedsExploreFromCollectionMenu($page);
    $page->click('@dialog-close');
    assertCreateFeedsExploreClosed($page, $path);

    $page->assertNoJavaScriptErrors();
    expect(RssFeed::query()->where('url', 'http://93.184.216.34/feed.xml')->sole()->rss_feed_collection_id)->toBe($collection->id);
});

test('explore opens repeatedly without touching the url and loads the directory behind a skeleton', function () {
    [$user] = createFeedsSetup();
    $this->actingAs($user);

    $path = route('app.create.feeds.index', [], false);
    $page = visit(route('app.create.feeds.index'));
    $page->script(<<<'JS'
        (() => {
            window.__createFeedsUrls = [];
            const record = () => window.__createFeedsUrls.push(window.location.pathname + window.location.search);
            const push = history.pushState.bind(history);
            const replace = history.replaceState.bind(history);
            history.pushState = (...args) => { push(...args); record(); };
            history.replaceState = (...args) => { replace(...args); record(); };
        })();
    JS);

    foreach (range(1, 2) as $round) {
        openCreateFeedsExploreFromSplitButton($page);
        waitForCreateFeedsTestId($page, 'feeds-explore-grid-favorites');
        $page->assertVisible('@feeds-explore-grid-favorites')->assertMissing('@feeds-explore-loading');
        $page->keys('@feeds-explore-dialog', 'Escape');
        assertCreateFeedsExploreClosed($page, $path);
    }

    expect(array_unique($page->script('window.__createFeedsUrls')))->toBe([$path]);
    $page->assertNoJavaScriptErrors();
});

test('the feeds dialogs mount without accessibility or attribute warnings', function () {
    [$user, $workspace] = createFeedsSetup();
    createFeedsFeed($workspace);
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-new-menu');
    $page->script(<<<'JS'
        (() => {
            window.__createFeedsWarnings = [];
            const warn = console.warn;
            console.warn = (...args) => {
                window.__createFeedsWarnings.push(args.map(String).join(' '));
                warn(...args);
            };
        })();
    JS);

    $page->click('@feeds-new-menu');
    waitForCreateFeedsTestId($page, 'feeds-new-collection');
    $page->click('@feeds-new-collection');
    waitForCreateFeedsDialog($page, 'feeds-new-collection-dialog');
    $page->click('@feeds-new-collection-dialog-cancel');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-new-collection-dialog\"]')");

    expect($page->script('window.__createFeedsWarnings'))->toBe([]);

    $page->click('@feeds-new');
    waitForCreateFeedsDialog($page, 'feeds-add-dialog');
    $page->click('@feeds-add-cancel');
    waitForCreateFeedsCondition($page, "!document.querySelector('[data-testid=\"feeds-add-dialog\"]')");

    openCreateFeedsExploreFromSplitButton($page);

    expect($page->script('window.__createFeedsWarnings'))->toBe([]);
    $page->assertNoJavaScriptErrors();
});

test('closing explore keeps the item list loading further pages without duplicates', function () {
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace);
    foreach (range(1, 45) as $minutes) {
        createFeedsItem($feed, ['published_at' => now()->subMinutes($minutes)]);
    }
    $this->actingAs($user);

    $articles = "[...document.querySelectorAll('article[data-testid^=\"feed-item-\"]')]";
    $scrollToEnd = "document.querySelector('[data-testid=\"feeds-scroll\"]').scrollTop = 100000";

    $page = visit(route('app.create.feeds.index'));
    waitForCreateFeedsTestId($page, 'feeds-items');
    $page->script($scrollToEnd);
    waitForCreateFeedsTestId($page, 'feeds-caught-up');

    openCreateFeedsExploreFromSplitButton($page);
    $page->click('@dialog-close');
    assertCreateFeedsExploreClosed($page, route('app.create.feeds.index', [], false));

    $page->script($scrollToEnd);
    waitForCreateFeedsCondition($page, "{$articles}.length === 45");

    expect($page->script("{$articles}.length"))->toBe(45)
        ->and($page->script("new Set({$articles}.map((article) => article.dataset.testid)).size"))->toBe(45);
    $page->assertVisible('@feeds-caught-up')->assertNoJavaScriptErrors();
});

test('the create tabs stay put while the chips scroll away with the feed list', function () {
    [$user, $workspace] = createFeedsSetup();
    $feed = createFeedsFeed($workspace);
    foreach (range(1, 20) as $minutes) {
        createFeedsItem($feed, ['published_at' => now()->subMinutes($minutes)]);
    }
    $this->actingAs($user);

    $page = visit(route('app.create.feeds.index'))->resize(1280, 700);
    waitForCreateFeedsTestId($page, 'feeds-items');

    $tabsTop = "document.querySelector('[data-testid=\"create-tabs\"]').getBoundingClientRect().top";
    $chipsBottom = "document.querySelector('[data-testid=\"feeds-chips\"]').getBoundingClientRect().bottom";
    $tabsBottom = "document.querySelector('[data-testid=\"create-tabs\"]').getBoundingClientRect().bottom";
    $before = $page->script($tabsTop);

    $page->script("document.querySelector('[data-testid=\"feeds-scroll\"]').scrollTop = 600");
    waitForCreateFeedsCondition($page, "document.querySelector('[data-testid=\"feeds-scroll\"]').scrollTop > 0");

    expect($page->script($tabsTop))->toBe($before)
        ->and($page->script("{$chipsBottom} <= {$tabsBottom}"))->toBeTrue();
    $page->assertVisible('@create-tab-feeds')->assertNoJavaScriptErrors();
});
