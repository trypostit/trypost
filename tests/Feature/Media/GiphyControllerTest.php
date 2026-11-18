<?php

declare(strict_types=1);

use App\Dto\ImportedFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    fakePublicDns();
    Cache::flush();
    config()->set([
        'services.giphy.api_key' => 'giphy-key',
        'trypost.media_sources.giphy.enabled' => true,
    ]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->account);
});

/**
 * One GIF as the GIPHY API returns it.
 *
 * @return array<string, mixed>
 */
function giphyApiGif(string $id, array $overrides = []): array
{
    return [
        'id' => $id,
        'title' => "Dancing {$id} GIF",
        'url' => "https://giphy.com/gifs/dancing-{$id}",
        'images' => [
            'fixed_width' => ['url' => "https://media1.giphy.com/media/{$id}/200w.gif", 'width' => '200', 'height' => '150'],
            'downsized_medium' => ['url' => "https://media1.giphy.com/media/{$id}/giphy.gif", 'size' => '1048576'],
        ],
        ...$overrides,
    ];
}

/**
 * @param  list<array<string, mixed>>  $gifs
 * @return array<string, mixed>
 */
function giphyApiPage(array $gifs, int $total, int $offset = 0): array
{
    return ['data' => $gifs, 'pagination' => ['total_count' => $total, 'count' => count($gifs), 'offset' => $offset], 'meta' => ['status' => 200]];
}

/**
 * @return array<string, mixed>
 */
function giphyPick(array $overrides = []): array
{
    return [
        'source' => Source::Giphy->value,
        'url' => 'https://media1.giphy.com/media/abc123/giphy.gif',
        'gif_id' => 'abc123',
        'giphy_url' => 'https://giphy.com/gifs/dancing-abc123',
        ...$overrides,
    ];
}

function giphyApi(string $path): string
{
    return config('trypost.media_sources.giphy.api').$path;
}

test('trending gifs come back in the picker shape, with a next page while giphy has more', function () {
    Http::fake([giphyApi('/gifs/trending*') => Http::response(giphyApiPage([giphyApiGif('one'), giphyApiGif('two')], 100))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertOk()
        ->assertJsonPath('has_more', true)
        ->assertJsonCount(2, 'results')
        ->assertJsonPath('results.0', [
            'id' => 'one',
            'title' => 'Dancing one GIF',
            'url_preview' => 'https://media1.giphy.com/media/one/200w.gif',
            'url' => 'https://media1.giphy.com/media/one/giphy.gif',
            'width' => 200,
            'height' => 150,
            'giphy_url' => 'https://giphy.com/gifs/dancing-one',
        ]);

    Http::assertSent(fn ($request) => $request['api_key'] === 'giphy-key'
        && $request['rating'] === 'g'
        && (int) $request['offset'] === 0);
});

test('a search asks giphy for the page offset and stops when there is no more', function () {
    $perPage = (int) config('app.pagination.default');
    Http::fake([giphyApi('/gifs/search*') => Http::response(giphyApiPage([giphyApiGif('last')], $perPage + 1, $perPage))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.search', ['query' => '  Happy Dance ', 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('has_more', false)
        ->assertJsonPath('results.0.id', 'last');

    Http::assertSent(fn ($request) => $request['q'] === 'happy dance' && (int) $request['offset'] === $perPage);
});

test('a gif without a rendition to import is left out', function () {
    Http::fake([giphyApi('/*') => Http::response(giphyApiPage([
        giphyApiGif('kept'),
        giphyApiGif('dropped', ['images' => ['fixed_width' => ['url' => 'https://media1.giphy.com/x/200w.gif']]]),
    ], 2))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertOk()
        ->assertJsonCount(1, 'results')
        ->assertJsonPath('results.0.id', 'kept');
});

test('giphy being down answers 503 with a message, and is not cached', function () {
    Http::fake([giphyApi('/*') => Http::sequence()
        ->push(['message' => 'Too many requests'], 429)
        ->push(giphyApiPage([giphyApiGif('back')], 1))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertStatus(503)
        ->assertJsonPath('message', __('posts.composer.giphy.error'));

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertOk()
        ->assertJsonPath('results.0.id', 'back');
});

test('a page giphy answered is cached for the next user', function () {
    Http::fake([giphyApi('/*') => Http::response(giphyApiPage([giphyApiGif('one')], 1))]);

    $this->actingAs($this->user)->getJson(route('app.media.giphy.trending'))->assertOk();
    $this->actingAs($this->user)->getJson(route('app.media.giphy.trending'))->assertOk();

    Http::assertSentCount(1);
});

test('while giphy is off or has no key the picker gets nothing and giphy is never called', function (array $config) {
    config()->set($config);
    Http::fake();

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertOk()
        ->assertExactJson(['results' => [], 'has_more' => false]);

    Http::assertNothingSent();
})->with([
    'no key' => [['services.giphy.api_key' => null]],
    'turned off' => [['trypost.media_sources.giphy.enabled' => false]],
]);

test('a picked gif imports through the queue as a temporary upload with its giphy attribution', function () {
    $gif = file_get_contents(base_path('tests/fixtures/1x1.gif'));
    Http::fake(['media1.giphy.com/*' => Http::response($gif, 200, ['Content-Type' => 'image/gif'])]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), giphyPick())
        ->assertAccepted()
        ->json('import_id');

    $status = $this->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::DONE)
        ->assertJsonPath('media.type', MediaType::Image->value)
        ->assertJsonPath('media.mime_type', 'image/gif');

    $media = Media::query()->sole();

    expect($status->json('media.upload_token'))->toBe($media->upload_token)
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->original_filename)->toBe('giphy-abc123.gif')
        ->and(data_get($media->meta, 'source'))->toBe(Source::Giphy->value)
        ->and(data_get($media->meta, 'source_meta'))->toEqual([
            'gif_id' => 'abc123',
            'giphy_url' => 'https://giphy.com/gifs/dancing-abc123',
        ])
        ->and(Storage::get($media->path))->toBe($gif);
});

test('a gif off the giphy hosts is refused before any request', function (array $overrides, string $field) {
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), giphyPick($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    Http::assertNothingSent();
    $this->assertDatabaseEmpty(Media::class);
})->with([
    'another host' => [['url' => 'https://images.unsplash.com/photo.gif'], 'url'],
    'a look-alike host' => [['url' => 'https://media1.giphy.com.evil.test/x.gif'], 'url'],
    'plain http' => [['url' => 'http://media1.giphy.com/media/abc123/giphy.gif'], 'url'],
    'a page off the giphy website' => [['giphy_url' => 'https://evil.test/gifs/abc123'], 'giphy_url'],
    'an id that is not one' => [['gif_id' => '../../etc'], 'gif_id'],
]);

test('gif picks are refused while giphy is off or has no key', function (array $config) {
    config()->set($config);
    Http::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), giphyPick())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    Http::assertNothingSent();
})->with([
    'no key' => [['services.giphy.api_key' => null]],
    'turned off' => [['trypost.media_sources.giphy.enabled' => false]],
]);

test('a file giphy serves that is not an image is refused and leaves nothing', function () {
    Http::fake(['media1.giphy.com/*' => Http::response('<html>not a gif</html>', 200, ['Content-Type' => 'text/html'])]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), giphyPick())
        ->assertAccepted()
        ->json('import_id');

    $this->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::FAILED);

    $this->assertDatabaseEmpty(Media::class);
});

test('a video giphy serves is refused, since a pick is a gif', function () {
    Http::fake(['media1.giphy.com/*' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')), 200, ['Content-Type' => 'video/mp4'])]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), giphyPick(['url' => 'https://media1.giphy.com/media/abc123/giphy.mp4']))
        ->assertAccepted()
        ->json('import_id');

    $this->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::FAILED)
        ->assertJsonPath('reason', ImportedFile::TYPE_NOT_ALLOWED);

    $this->assertDatabaseEmpty(Media::class);
});

test('searching giphy is throttled per user, while another user keeps searching', function () {
    config()->set('trypost.media_sources.giphy.requests_per_user_per_minute', 2);
    Http::fake([giphyApi('/*') => Http::response(giphyApiPage([giphyApiGif('one')], 1))]);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->user)->getJson(route('app.media.giphy.search', ['query' => "cats {$attempt}"]))->assertOk();
    }

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending'))
        ->assertTooManyRequests();

    $other = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($other->id, membershipPivot('member'));
    $other->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($other)->getJson(route('app.media.giphy.trending'))->assertOk();
});

test('the last page giphy can serve has no next one, whatever its total says', function () {
    config()->set('app.pagination.default', 100);
    $perPage = 100;
    $lastPage = intdiv(4999, $perPage) + 1;
    Http::fake([giphyApi('/*') => Http::response(giphyApiPage(Collection::times($perPage, fn (int $i): array => giphyApiGif("g{$i}"))->all(), 100000, ($lastPage - 1) * $perPage))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending', ['page' => $lastPage]))
        ->assertOk()
        ->assertJsonPath('has_more', false);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.trending', ['page' => $lastPage - 1]))
        ->assertOk()
        ->assertJsonPath('has_more', true);
});

test('an empty page ends the list, even when giphy counts more results', function () {
    Http::fake([giphyApi('/*') => Http::response(giphyApiPage([], 5000, 25))]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.giphy.search', ['query' => 'rare', 'page' => 2]))
        ->assertOk()
        ->assertExactJson(['results' => [], 'has_more' => false]);
});
