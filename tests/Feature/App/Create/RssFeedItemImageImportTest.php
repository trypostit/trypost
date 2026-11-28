<?php

declare(strict_types=1);

use App\Enums\Media\Type;
use App\Enums\PostPlatform\ContentType;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function rssFeedItemImageImportPng(): string
{
    return (string) file_get_contents(base_path('tests/fixtures/1x1.png'));
}

function rssFeedItemImageImportItem(Workspace $workspace, ?string $imageUrl = 'https://93.184.216.34/image.png'): RssFeedItem
{
    $feed = RssFeed::factory()->create(['workspace_id' => $workspace->id]);

    return RssFeedItem::factory()->for($feed, 'feed')->create([
        'title' => 'Item with image',
        'excerpt' => 'Excerpt',
        'url' => 'https://93.184.216.34/post',
        'image_url' => $imageUrl,
    ]);
}

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('saving as an idea imports the image onto the item and the idea owns a copy', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionHasNoErrors();

    $image = $item->image()->sole();
    $idea = Idea::query()->sole();
    $copy = $idea->ownedMedia()->sole();

    expect($image->rss_feed_item_id)->toBe($item->id)
        ->and($image->workspace_id)->toBe($this->workspace->id)
        ->and($image->mediable_type)->toBeNull()
        ->and($image->upload_token)->toBeNull()
        ->and($image->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($image->type)->toBe(Type::Image)
        ->and(Storage::exists($image->path))->toBeTrue()
        ->and($copy->path)->not->toBe($image->path)
        ->and(data_get($copy->meta, 'copied_from'))->toBe($image->id)
        ->and(Storage::exists($copy->path))->toBeTrue()
        ->and(collect($idea->media)->pluck('id')->all())->toBe([$copy->id])
        ->and(Media::query()->count())->toBe(2);
});

test('a non-image body is skipped and the idea is still created', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionHasNoErrors();

    expect(Media::query()->count())->toBe(0)
        ->and(Idea::query()->sole()->media)->toBe([]);
});

test('a private image host is never requested', function (string $imageUrl) {
    Http::fake();
    $item = rssFeedItemImageImportItem($this->workspace, $imageUrl);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionHasNoErrors();

    Http::assertNothingSent();
    expect(Media::query()->count())->toBe(0)
        ->and(Idea::query()->count())->toBe(1);
})->with([
    'https://127.0.0.1/image.png',
    'https://169.254.169.254/latest/image.png',
]);

test('an image over the size cap is skipped', function () {
    config()->set('trypost.media.max_size_mb.image', 1);
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng().str_repeat("\0", Type::Image->maxSizeInBytes()), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionHasNoErrors();

    expect(Media::query()->count())->toBe(0)
        ->and(Idea::query()->sole()->media)->toBe([]);
});

test('a second save reuses the imported image and each idea owns its own copy', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item));
    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item));
    $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))
        ->assertOk()
        ->assertJsonPath('data.id', $item->image()->sole()->id);

    $image = $item->image()->sole();
    $copies = Idea::query()->get()->map(fn (Idea $idea) => $idea->ownedMedia()->sole());

    Http::assertSentCount(1);
    expect($copies)->toHaveCount(2)
        ->and($copies->map(fn (Media $media) => data_get($media->meta, 'copied_from'))->all())->toBe([$image->id, $image->id])
        ->and($copies->pluck('path')->push($image->path)->unique())->toHaveCount(3)
        ->and(Media::query()->count())->toBe(3);
});

test('a deleted image is imported again', function () {
    Http::fake(['https://93.184.216.34/image.png' => fn () => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.import-image', $item));
    Media::query()->sole()->delete();

    $this->actingAs($this->user)->post(route('app.create.feed-items.import-image', $item))->assertOk();

    Http::assertSentCount(2);
    expect(Media::query()->count())->toBe(1);
});

test('the import endpoint returns the image the item owns', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $response = $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertOk();

    $media = $item->image()->sole();

    $response->assertJsonPath('data.id', $media->id)
        ->assertJsonPath('data.type', 'image')
        ->assertJsonPath('data.url', $media->url);
});

test('the import endpoint returns null data when there is nothing to import', function (?string $imageUrl) {
    Http::fake(['https://93.184.216.34/image.png' => Http::response('nope', 404)]);
    $item = rssFeedItemImageImportItem($this->workspace, $imageUrl);

    $this->actingAs($this->user)
        ->postJson(route('app.create.feed-items.import-image', $item))
        ->assertOk()
        ->assertExactJson(['data' => null]);
})->with([
    'no image' => [null],
    'failed download' => ['https://93.184.216.34/image.png'],
]);

test('another workspace item cannot be imported or saved', function () {
    Http::fake();
    $item = rssFeedItemImageImportItem(Workspace::factory()->create());

    $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertForbidden();
    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertForbidden();

    Http::assertNothingSent();
    expect(Media::query()->count())->toBe(0)
        ->and(Idea::query()->count())->toBe(0);
});

test('a user outside the workspace cannot import an image', function () {
    Http::fake();
    $outsider = workspaceOutsider($this->workspace);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($outsider->fresh())->postJson(route('app.create.feed-items.import-image', $item))->assertForbidden();

    Http::assertNothingSent();
});

test('an image behind a redirect to a public host is imported', function () {
    Http::fake([
        'https://93.184.216.34/image.png' => Http::response('', 301, ['Location' => 'https://1.1.1.1/cdn/image.png']),
        'https://1.1.1.1/cdn/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertOk();

    expect(Media::query()->count())->toBe(1);
});

test('an image redirect to a private host is never followed', function () {
    Http::fake([
        'https://93.184.216.34/image.png' => Http::response('', 301, ['Location' => 'http://169.254.169.254/latest/image.png']),
        'http://169.254.169.254/*' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png']),
    ]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertOk()->assertExactJson(['data' => null]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    expect(Media::query()->count())->toBe(0);
});

test('deleting a feed deletes the item image but not the copy an idea owns', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertSessionHasNoErrors();
    $image = $item->image()->sole();
    $copy = Idea::query()->sole()->ownedMedia()->sole();

    $this->actingAs($this->user)->delete(route('app.create.feeds.destroy', $item->rss_feed_id))->assertRedirect();

    expect(RssFeedItem::query()->count())->toBe(0)
        ->and(Media::query()->pluck('id')->all())->toBe([$copy->id])
        ->and(Storage::exists($image->path))->toBeFalse()
        ->and(Storage::exists($copy->path))->toBeTrue();
});

test('creating a post from a feed item copies its image and the item keeps its own', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    $imported = $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertOk()->json('data');

    $this->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'From the feed',
        'media' => [$imported],
        'destinations' => [['social_account_id' => $channel->id, 'content_type' => ContentType::LinkedInPost->value]],
    ])->assertSessionHasNoErrors();

    $image = $item->image()->sole();
    $copy = Post::query()->sole()->ownedMedia()->sole();

    expect(data_get($copy->meta, 'copied_from'))->toBe($image->id)
        ->and($copy->path)->not->toBe($image->path)
        ->and(Storage::exists($copy->path))->toBeTrue()
        ->and(Storage::exists($image->path))->toBeTrue()
        ->and($image->fresh()->rss_feed_item_id)->toBe($item->id);
});

test('the image is requested uncompressed so a compressed bomb cannot expand on disk', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(gzencode(str_repeat('0', 4096)), 200, ['Content-Type' => 'image/png', 'Content-Encoding' => 'gzip'])]);
    $item = rssFeedItemImageImportItem($this->workspace);
    $before = glob(sys_get_temp_dir().'/media_*') ?: [];

    $this->actingAs($this->user)->postJson(route('app.create.feed-items.import-image', $item))->assertOk()->assertExactJson(['data' => null]);

    Http::assertSent(fn ($request) => $request->hasHeader('Accept-Encoding', 'identity'));
    expect(Media::query()->count())->toBe(0)
        ->and(glob(sys_get_temp_dir().'/media_*') ?: [])->toBe($before);
});

test('saving as an idea and importing an image are throttled the same way', function () {
    Http::fake(['https://93.184.216.34/image.png' => Http::response(rssFeedItemImageImportPng(), 200, ['Content-Type' => 'image/png'])]);
    $item = rssFeedItemImageImportItem($this->workspace);

    foreach (range(1, 30) as $attempt) {
        $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect();
    }

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertStatus(429);
});

test('an item without a link is served with a null url and saves an idea without the word null', function () {
    Http::fake();
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = RssFeedItem::factory()->for($feed, 'feed')->create(['title' => 'No link', 'excerpt' => 'Only text', 'url' => null, 'image_url' => null]);

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertInertia(fn ($page) => $page->where('items.data.0.url', null));

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionHasNoErrors();

    expect(Idea::query()->sole()->body)->toBe('Only text');
});
