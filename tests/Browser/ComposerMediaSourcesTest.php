<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;

function openComposerForMediaSources(mixed $test): mixed
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $test->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitFor($page, '@composer-base-toolbar');

    return $page;
}

function chooseComposerMediaSource(mixed $page, string $prefix, string $source): void
{
    $item = "document.querySelector('[data-testid=\"media-source-{$source}\"]')?.getBoundingClientRect().height > 0";

    for ($attempt = 0; $attempt < 3 && ! $page->script("Boolean({$item})"); $attempt++) {
        $page->click("@{$prefix}-media-source-menu");
        waitFor($page, "@media-source-{$source}");
    }

    $page->click("@media-source-{$source}");
}

/**
 * @return array<string, mixed>
 */
function unsplashBrowserPhoto(string $id, string $author): array
{
    $api = config('trypost.media_sources.unsplash.api');
    $images = Source::Unsplash->downloadHosts()[0];
    $website = config('trypost.media_sources.unsplash.website');

    return [
        'id' => $id,
        'width' => 4,
        'height' => 3,
        'alt_description' => "Photo {$id}",
        'urls' => [
            'small' => 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('tests/fixtures/1x1.png'))),
            'regular' => "https://{$images}/photo-{$id}",
            'full' => "https://{$images}/photo-{$id}",
        ],
        'links' => ['download_location' => "{$api}/photos/{$id}/download"],
        'user' => ['name' => $author, 'links' => ['html' => "{$website}/@{$id}"]],
    ];
}

/**
 * Trending answers page 1 with one photo, page 2 with another and nothing
 * after that; `$apiStatus` makes every Unsplash API call fail instead.
 */
function enableOnlyUnsplashForMediaSources(int $apiStatus = 200): void
{
    fakePublicDns();
    config()->set([
        'services.unsplash.access_key' => 'unsplash-key',
        'services.giphy.api_key' => null,
        'services.google_media.client_id' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
    ]);

    $api = config('trypost.media_sources.unsplash.api');
    $images = Source::Unsplash->downloadHosts()[0];
    $png = file_get_contents(base_path('tests/fixtures/1x1.png'));

    Http::fake(function (Request $request) use ($api, $images, $png, $apiStatus) {
        $url = $request->url();

        if (str_starts_with($url, "https://{$images}/")) {
            return Http::response($png, 200, ['Content-Type' => 'image/jpeg']);
        }
        if (! str_starts_with($url, "{$api}/")) {
            return Http::response('', 404);
        }
        if ($apiStatus !== 200) {
            return Http::response(['errors' => ['Rate Limit Exceeded']], $apiStatus);
        }
        if (str_contains($url, '/download')) {
            return Http::response(['url' => "https://{$images}/photo"]);
        }

        return Http::response(match ((int) data_get($request->data(), 'page', 1)) {
            1 => [unsplashBrowserPhoto('sunrise', 'Ana Photographer')],
            2 => [unsplashBrowserPhoto('dusk', 'Bruno Photographer')],
            default => [],
        });
    });
}

test('the toolbar has one media button whose menu lists the upload first, then the sources', function () {
    enableOnlyUnsplashForMediaSources();
    config()->set([
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'services.giphy.api_key' => 'giphy-key',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.google_drive.enabled' => false,
        'trypost.media_sources.google_photos.enabled' => false,
    ]);

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');

    $page->assertNotPresent('@composer-base-add-media')
        ->assertNotPresent('@composer-base-media-source-main')
        ->assertAttribute('@composer-base-media-source-menu', 'aria-label', __('posts.edit.add_media'));

    expect($page->script("document.querySelectorAll('[data-testid=\"composer-base-toolbar\"] button[aria-haspopup=\"menu\"]').length"))->toBe(1);

    expect($page->script(<<<'JS'
        (() => {
            const boxes = ['composer-base-media-source-menu', 'composer-base-emoji', 'composer-base-signature']
                .map((id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect());
            const icons = ['composer-base-media-source-menu', 'composer-base-emoji', 'composer-base-signature']
                .map((id) => document.querySelector(`[data-testid="${id}"] svg`).getBoundingClientRect().width);
            return {
                heights: new Set(boxes.map((box) => Math.round(box.height))).size,
                centers: new Set(boxes.map((box) => Math.round(box.top + box.height / 2))).size,
                icons: new Set(icons.map(Math.round)).size,
            };
        })()
    JS))->toBe(['heights' => 1, 'centers' => 1, 'icons' => 1]);

    $page->click('@composer-base-media-source-menu');
    waitFor($page, '@composer-base-media-source-list');

    expect($page->script("Array.from(document.querySelectorAll('[data-testid=\"composer-base-media-source-list\"] [data-testid^=\"media-source-\"]')).map((item) => item.dataset.testid)"))
        ->toBe(['media-source-upload', 'media-source-canva', 'media-source-giphy', 'media-source-unsplash']);

    expect($page->script(<<<'JS'
        (() => {
            const mark = document.querySelector('[data-testid="media-source-unsplash"] svg');

            return getComputedStyle(mark).fill === getComputedStyle(mark).color;
        })()
    JS))->toBeTrue();

    $page->click('@media-source-unsplash');
    waitFor($page, '@unsplash-dialog');

    $page->assertNoJavaScriptErrors();
});

test('upload from computer in the media menu opens the file picker', function (string $route, string $prefix) {
    enableOnlyUnsplashForMediaSources();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route($route));
    waitFor($page, "@{$prefix}-media-source-menu");
    $page->script("HTMLInputElement.prototype.click = function () { if (this.type === 'file') { window.__filePicker = (window.__filePicker ?? 0) + 1; } }; true;");

    chooseComposerMediaSource($page, $prefix, 'upload');
    waitForCondition($page, 'window.__filePicker === 1');

    expect($page->script('window.__filePicker ?? 0'))->toBe(1);
    $page->assertNoJavaScriptErrors();
})->with([
    'post composer' => ['app.posts.create', 'composer-base'],
]);

test('picking an unsplash photo from the menu adds a tile and credits the photographer', function () {
    enableOnlyUnsplashForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    $page->click('@composer-base-media-source-menu');
    waitFor($page, '@media-source-unsplash');
    $page->click('@media-source-unsplash');
    waitFor($page, '@unsplash-dialog');
    waitFor($page, '@unsplash-photo-0');

    $page->assertSeeIn('@unsplash-dialog', 'Ana Photographer');
    expect($page->script("document.querySelector('[data-testid=\"unsplash-dialog\"] a[href*=\"@sunrise\"]')?.getAttribute('href')"))
        ->toEndWith('@sunrise?utm_source=trypost&utm_medium=referral');

    $page->click('@unsplash-photo-0');
    waitFor($page, '@composer-media-item');
    waitForGone($page, '@unsplash-dialog');

    $page->assertPresent('@composer-media-item')
        ->assertNotPresent('@unsplash-dialog')
        ->assertNoJavaScriptErrors();

    $media = Media::query()->sole();
    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and(data_get($media->meta, 'source'))->toBe(Source::Unsplash->value)
        ->and(data_get($media->meta, 'source_meta.author_name'))->toBe('Ana Photographer');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/photos/sunrise/download'));
});

test('the unsplash dialog keeps loading pages as the grid scrolls and has no footer', function () {
    enableOnlyUnsplashForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    chooseComposerMediaSource($page, 'composer-base', 'unsplash');
    waitFor($page, '@unsplash-photo-1');

    $page->assertSeeIn('@unsplash-dialog', 'Bruno Photographer')
        ->assertNotPresent('@unsplash-photo-2')
        ->assertNotPresent('@unsplash-error')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"unsplash-dialog\"] [data-slot=\"dialog-footer\"]') === null"))->toBeTrue();
});

test('an unsplash outage shows an error instead of an empty result', function () {
    enableOnlyUnsplashForMediaSources(apiStatus: 403);

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    chooseComposerMediaSource($page, 'composer-base', 'unsplash');
    waitFor($page, '@unsplash-error');

    $page->assertSeeIn('@unsplash-error', "Unsplash isn't available right now.")
        ->assertNotPresent('@unsplash-empty')
        ->assertNoJavaScriptErrors();
});

/**
 * @return array<string, mixed>
 */
function giphyBrowserGif(string $id): array
{
    $png = 'data:image/png;base64,'.base64_encode(file_get_contents(base_path('tests/fixtures/1x1.png')));

    return [
        'id' => $id,
        'title' => "Dancing {$id} GIF",
        'url' => "https://giphy.com/gifs/dancing-{$id}",
        'images' => [
            'fixed_width' => ['url' => $png, 'width' => '200', 'height' => '150'],
            'downsized_medium' => ['url' => "https://media1.giphy.com/media/{$id}/giphy.gif"],
        ],
    ];
}

/**
 * Trending answers one GIF on page 1 and says there is a second page with
 * another; `$apiStatus` makes every GIPHY API call fail instead.
 */
function enableOnlyGiphyForMediaSources(int $apiStatus = 200): void
{
    fakePublicDns();
    Cache::flush();
    config()->set([
        'services.giphy.api_key' => 'giphy-key',
        'services.unsplash.access_key' => null,
        'services.google_media.client_id' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
    ]);

    $api = config('trypost.media_sources.giphy.api');
    $gif = file_get_contents(base_path('tests/fixtures/1x1.gif'));
    $trendingPage = fn (string $id, int $offset): array => ['data' => [giphyBrowserGif($id)], 'pagination' => ['total_count' => 2, 'count' => 1, 'offset' => $offset]];

    if ($apiStatus !== 200) {
        Http::fake([
            "{$api}/*" => Http::response(['message' => 'Too many requests'], $apiStatus),
            '*' => Http::response('', 404),
        ]);

        return;
    }

    Http::fake([
        'media1.giphy.com/*' => Http::response($gif, 200, ['Content-Type' => 'image/gif']),
        "{$api}/gifs/search*" => Http::response(['data' => [giphyBrowserGif('happy')], 'pagination' => ['total_count' => 1, 'count' => 1, 'offset' => 0]]),
        "{$api}/gifs/trending*" => function (Request $request) use ($trendingPage) {
            if ((int) Uri::of($request->url())->query()->get('offset', 0) === 0) {
                return Http::response($trendingPage('party', 0));
            }

            return Http::response($trendingPage('cheers', 1));
        },
        '*' => Http::response('', 404),
    ]);
}

test('picking a gif from giphy adds a tile and keeps where it came from', function () {
    enableOnlyGiphyForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-dialog');
    waitFor($page, '@giphy-gif-0');

    $page->assertAttribute('@giphy-gif-0', 'aria-label', 'Dancing party GIF');

    $page->click('@giphy-gif-0');
    waitFor($page, '@composer-media-item');
    waitForGone($page, '@giphy-dialog');

    $page->assertPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();

    $media = Media::query()->sole();
    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->mime_type)->toBe('image/gif')
        ->and(data_get($media->meta, 'source'))->toBe(Source::Giphy->value)
        ->and(data_get($media->meta, 'source_meta.gif_id'))->toBe('party');
});

test('a picked gif loads on the post until the socket says its import settled, with no polling', function () {
    enableOnlyGiphyForMediaSources();
    Queue::fake([ImportRemoteMedia::class]);

    $page = openComposerForMediaSources($this);
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-gif-0');
    $page->click('@giphy-gif-0');
    waitForGone($page, '@giphy-dialog');
    waitFor($page, '@composer-import-item');

    $job = Queue::pushed(ImportRemoteMedia::class)->sole();
    app()->call([$job, 'handle']);
    letPageRun($page, 3000);

    $page->assertPresent('@composer-import-item')
        ->assertMissing('@composer-media-item');

    broadcastToPage($page, "workspace.{$job->workspaceId}", 'media.import.settled', ['import_id' => $job->importId]);
    waitFor($page, '@composer-media-item');

    $page->assertMissing('@composer-import-item')
        ->assertNoJavaScriptErrors();
    expect(Media::query()->sole()->mime_type)->toBe('image/gif');
});

test('an import that settled while the socket was down lands once the socket connects again', function () {
    enableOnlyGiphyForMediaSources();
    Queue::fake([ImportRemoteMedia::class]);

    $page = openComposerForMediaSources($this);
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-gif-0');
    $page->click('@giphy-gif-0');
    waitFor($page, '@composer-import-item');

    app()->call([Queue::pushed(ImportRemoteMedia::class)->sole(), 'handle']);
    reconnectPageSocket($page);
    waitFor($page, '@composer-media-item');

    $page->assertMissing('@composer-import-item')
        ->assertNoJavaScriptErrors();
});

test('a gif whose import cannot start says why and keeps the dialog open', function () {
    enableOnlyGiphyForMediaSources();

    $page = openComposerForMediaSources($this);
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-gif-0');

    config()->set('trypost.media_sources.giphy.enabled', false);
    $page->click('@giphy-gif-0');
    waitForText($page, '[data-sonner-toast]', __('validation.in', ['attribute' => 'source']));

    $page->assertPresent('@giphy-dialog')
        ->assertMissing('@composer-import-item')
        ->assertMissing('@composer-media-item')
        ->assertNoJavaScriptErrors();
});

test('a gif picked while a thread reply is edited lands on that reply, not on the post', function () {
    enableOnlyGiphyForMediaSources();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, ContentType::XPost)->create(['user_id' => $user->id, 'content' => 'The thread starts here']);
    $this->actingAs($user);
    $id = $account->id;

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, '@thread-start');
    $page->click('@thread-start');
    waitFor($page, '@thread-reply-0');

    chooseComposerMediaSource($page, "composer-{$id}", 'giphy');
    waitFor($page, '@giphy-gif-0');
    $page->click('@giphy-gif-0');
    waitFor($page, "@composer-{$id}-reply-0-media-item-0");

    $page->assertMissing("@composer-{$id}-media-item-0")
        ->assertNotPresent('@giphy-dialog')
        ->assertNoJavaScriptErrors();
});

test('a gif picked in the idea editor joins the idea', function () {
    enableOnlyGiphyForMediaSources();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'idea_stage_id' => null, 'position' => 0, 'title' => 'GIF idea']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.show', $idea));
    waitFor($page, '@idea-editor-media-source-menu');

    chooseComposerMediaSource($page, 'idea-editor', 'giphy');
    waitFor($page, '@giphy-gif-0');
    $page->click('@giphy-gif-0');
    waitFor($page, '@idea-media-item-0');
    waitForGone($page, '@giphy-dialog');

    $page->click('@idea-editor-save');
    waitForGone($page, '@idea-editor');

    $media = Media::query()->sole();

    expect($media->idea_id)->toBe($idea->id)
        ->and(data_get($media->meta, 'source'))->toBe(Source::Giphy->value)
        ->and(data_get($idea->refresh()->media, '0.id'))->toBe($media->id);

    $page->assertNoJavaScriptErrors();
});

test('the giphy dialog loads the next page and searches as the user types', function () {
    enableOnlyGiphyForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-gif-1');

    $page->assertAttribute('@giphy-gif-1', 'aria-label', 'Dancing cheers GIF')
        ->assertNotPresent('@giphy-error');

    $page->type('@giphy-search', 'happy');
    waitForState($page, '@giphy-gif-0', "element.getAttribute('aria-label') === 'Dancing happy GIF'");
    waitForCount($page, '[data-testid^="giphy-gif-"]', 1);

    $page->assertNoJavaScriptErrors();

    Http::assertSent(fn (Request $request) => Str::is(config('trypost.media_sources.giphy.api').'/gifs/search*', $request->url())
        && Uri::of($request->url())->query()->get('q') === 'happy');
});

test('a giphy outage shows an error instead of an empty result', function () {
    enableOnlyGiphyForMediaSources(apiStatus: 429);

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    chooseComposerMediaSource($page, 'composer-base', 'giphy');
    waitFor($page, '@giphy-error');

    $page->assertSeeIn('@giphy-error', __('posts.composer.giphy.error'))
        ->assertNotPresent('@giphy-empty')
        ->assertNoJavaScriptErrors();
});

function enableOnlyGoogleDriveForMediaSources(): string
{
    config()->set([
        'services.unsplash.access_key' => null,
        'services.giphy.api_key' => null,
        'services.google_media.client_id' => 'media-client-id',
        'services.google_media.client_secret' => 'media-client-secret',
        'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback',
        'services.google_media.api_key' => 'media-api-key',
        'services.google_media.app_id' => '123456789012',
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
        'trypost.media_sources.google_drive.enabled' => true,
    ]);

    test()->mock(HostResolver::class)->shouldReceive('addresses')->andReturn(['142.250.0.10']);

    $api = config('trypost.media_sources.google_drive.api');

    Http::fake([
        "{$api}/files/*" => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
    ]);

    return $api;
}

/**
 * A stand-in for the sign-in popup (recording the URL and whether it opened
 * inside the click) and for the Picker, already "loaded" so its SDK is never
 * fetched. The picker returns one PNG, at once or (`$holdOpen`) when
 * `window.__drive.finish()` runs, after mounting a focusable `.picker-dialog`
 * on body like the real one.
 */
function stubGoogleDriveForMediaSources(mixed $page, bool $holdOpen = false): void
{
    $holdOpenJs = $holdOpen ? 'true' : 'false';

    $page->script(<<<JS
        (() => {
            window.__drive = { opened: [], openedInClick: null, mimeTypes: null, pickerToken: null, appId: null, inClick: false, disposed: 0, finished: [] };
            const xhrOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.addEventListener('loadend', () => window.__drive.finished.push(`\${String(method).toUpperCase()} \${url}`));
                return xhrOpen.call(this, method, url, ...rest);
            };
            window.addEventListener('click', () => { window.__drive.inClick = true; }, true);
            window.addEventListener('click', () => { window.__drive.inClick = false; });
            window.open = (url) => {
                window.__drive.opened.push(url);
                window.__drive.openedInClick = window.__drive.inClick;
                return { closed: false, close() { this.closed = true; } };
            };
            window.gapi = { load: (library, config) => config.callback() };
            window.google = {
                picker: {
                    ViewId: { DOCS: 'all' },
                    Action: { PICKED: 'picked', CANCEL: 'cancel' },
                    Response: { ACTION: 'action', DOCUMENTS: 'docs' },
                    Document: { ID: 'id', NAME: 'name' },
                    DocsView: class {
                        setMimeTypes(mimeTypes) { window.__drive.mimeTypes = mimeTypes; return this; }
                        setIncludeFolders() { return this; }
                        setEnableDrives() { return this; }
                    },
                    PickerBuilder: class {
                        setAppId(appId) { window.__drive.appId = appId; return this; }
                        setDeveloperKey() { return this; }
                        setOAuthToken(token) { window.__drive.pickerToken = token; return this; }
                        setLocale() { return this; }
                        addView() { return this; }
                        enableFeature(feature) { window.__drive.feature = feature; return this; }
                        setCallback(callback) { this.callback = callback; return this; }
                        build() {
                            const callback = this.callback;
                            const pick = () => {
                                callback({ action: 'loaded' });
                                callback({ action: 'picked', docs: [{ id: '1AbC_dE-fGh', name: 'beach.png' }] });
                            };
                            return {
                                setVisible: () => {
                                    if (!{$holdOpenJs}) {
                                        setTimeout(pick, 0);
                                        return;
                                    }
                                    const dialog = document.createElement('div');
                                    dialog.className = 'picker-dialog';
                                    dialog.style.cssText = 'position:fixed;top:10px;left:10px;z-index:2000;background:#fff;padding:8px';
                                    dialog.innerHTML = '<input data-testid="drive-picker-search" aria-label="Search">';
                                    document.body.appendChild(dialog);
                                    dialog.querySelector('input').focus();
                                    window.__drive.finish = pick;
                                },
                                dispose: () => {
                                    window.__drive.disposed++;
                                    document.querySelector('.picker-dialog')?.remove();
                                },
                            };
                        }
                    },
                },
            };
        })();
    JS);
}

/**
 * The nonce the composer put on the sign-in popup it opened.
 */
function googleDriveSignInNonce(mixed $page): string
{
    $opened = $page->script('window.__drive.opened');
    parse_str((string) parse_url($opened[0], PHP_URL_QUERY), $query);

    expect($opened)->toHaveCount(1)
        ->and(parse_url($opened[0], PHP_URL_PATH))->toBe(route('app.integrations.google.start', [], false))
        ->and(data_get($query, 'source'))->toBe('google_drive')
        ->and(data_get($query, 'nonce'))->toMatch('/^[A-Za-z0-9_-]{16,64}$/')
        ->and($page->script('window.__drive.openedInClick'))->toBeTrue();

    return (string) data_get($query, 'nonce');
}

/**
 * What GoogleMediaController's callback leaves for the composer, then the
 * popup page's report: the composer claims the token from the server.
 */
function finishGoogleDriveSignIn(mixed $page, string $token = 'server-drive-token'): void
{
    $user = User::query()->sole();
    $nonce = googleDriveSignInNonce($page);

    Cache::put("google-media-return:{$user->id}:{$nonce}", [
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'source' => 'google_drive',
        'access_token' => Crypt::encryptString($token),
        'expires_at' => now()->addHour()->getTimestamp(),
    ], 600);

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'google_drive', nonce: '{$nonce}', success: true, import_id: null, replaces: null, message: null },
        }));
        true;
    JS);
}

function assertGoogleDriveImportForMediaSources(mixed $page, string $api): void
{
    expect($page->script('window.__drive.pickerToken'))->toBe('server-drive-token')
        ->and($page->script('window.__drive.appId'))->toBe('123456789012')
        ->and($page->script('window.__drive.feature ?? null'))->toBeNull()
        ->and($page->script('window.__drive.mimeTypes'))->toContain('image/png')
        ->and($page->script('window.__drive.mimeTypes'))->toContain('video/mp4');

    $media = Media::query()->sole();
    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->upload_token)->not->toBeNull()
        ->and(data_get($media->meta, 'source'))->toBe(Source::GoogleDrive->value)
        ->and(data_get($media->meta, 'source_meta.file_id'))->toBe('1AbC_dE-fGh');

    Http::assertSent(fn (Request $request) => $request->url() === "{$api}/files/1AbC_dE-fGh?alt=media&supportsAllDrives=true"
        && $request->hasHeader('Authorization', 'Bearer server-drive-token'));
}

test('picking a google drive file in a single channel adds a tile to that channel tray', function () {
    $api = enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    $account = SocialAccount::query()->sole();
    waitFor($page, '@composer-add-account');
    $page->click('@composer-add-account');
    $page->click("@composer-account-option-{$account->id}");
    waitFor($page, "@composer-{$account->id}-media-source-menu");
    $page->fill("@composer-caption-{$account->id}", 'Drive pick for one channel');

    stubGoogleDriveForMediaSources($page);
    $page->click("@composer-{$account->id}-media-source-menu");
    waitFor($page, '@media-source-google_drive');
    $page->click('@media-source-google_drive');
    finishGoogleDriveSignIn($page);
    waitFor($page, "@composer-{$account->id}-media-item");

    $page->assertPresent("@composer-{$account->id}-media-item")
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelectorAll('[data-testid=\"composer-media-item\"]').length"))->toBe(0);

    assertGoogleDriveImportForMediaSources($page, $api);
});

test('the drive picker can be clicked, focused and typed in over the composer dialog', function () {
    $api = enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    stubGoogleDriveForMediaSources($page, holdOpen: true);
    chooseComposerMediaSource($page, 'composer-base', 'google_drive');
    waitForGone($page, '@composer-base-media-source-list');
    finishGoogleDriveSignIn($page);
    waitFor($page, '@drive-picker-search');
    waitForState($page, '@drive-picker-search', 'element === document.activeElement');

    expect($page->script('document.activeElement?.dataset?.testid ?? null'))->toBe('drive-picker-search')
        ->and($page->script('getComputedStyle(document.body).pointerEvents'))->not->toBe('none');

    $page->click('@drive-picker-search');
    $page->type('@drive-picker-search', 'beach');

    expect($page->script('document.activeElement?.dataset?.testid ?? null'))->toBe('drive-picker-search')
        ->and($page->script("document.querySelector('[data-testid=\"drive-picker-search\"]').value"))->toBe('beach');
    $page->assertPresent('@post-composer-dialog');

    $page->script('window.__drive.finish()');
    waitFor($page, '@composer-media-item');

    $page->assertPresent('@post-composer-dialog')
        ->assertPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect($page->script('getComputedStyle(document.body).pointerEvents'))->toBe('none')
        ->and($page->script('window.__drive.disposed'))->toBe(1);

    assertGoogleDriveImportForMediaSources($page, $api);
});

test('a failed google drive sign-in opens no picker and shows the popup message', function () {
    enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    stubGoogleDriveForMediaSources($page);
    chooseComposerMediaSource($page, 'composer-base', 'google_drive');
    $nonce = googleDriveSignInNonce($page);
    $message = __('posts.composer.media_sources.errors.scope_not_granted');

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'google_drive', nonce: '{$nonce}', success: false, message: '{$message}' },
        }));
        true;
    JS);
    waitForCondition($page, "document.body.innerText.includes('TryPost needs access to the file you pick')");

    $page->assertSee($message)
        ->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect($page->script('window.__drive.pickerToken'))->toBeNull()
        ->and($page->script("window.__drive.finished.some((request) => request.includes('/integrations/google/returns/'))"))->toBeFalse()
        ->and(Media::query()->count())->toBe(0);
});

test('the real sign-in popup, start to callback, hands the drive token to the composer through the broadcast', function () {
    $api = enableOnlyGoogleDriveForMediaSources();
    config()->set([
        'trypost.media_sources.google_oauth.authorize_url' => route('app.integrations.google.callback').'?code=browser-code&ignored',
        'trypost.media_sources.google_oauth.token_api' => 'https://oauth2.google.test',
    ]);
    Http::fake([
        'https://oauth2.google.test/token' => Http::response(['access_token' => 'server-drive-token', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive.file']),
        "{$api}/files/*" => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
    ]);

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');
    stubGoogleDriveForMediaSources($page);
    $page->script('window.open = (url) => { window.__drive.opened.push(url); window.__drive.openedInClick = window.__drive.inClick; return { closed: true, close() {} }; }; true;');
    chooseComposerMediaSource($page, 'composer-base', 'google_drive');
    $opened = $page->script('window.__drive.opened[0]');

    $popup = visit($opened);
    waitFor($popup, '@media-source-popup');
    $popup->assertAttribute('@media-source-popup', 'data-success', 'true')
        ->assertSee(__('integrations.media_source_popup.google_drive_ready'));

    waitFor($page, '@composer-media-item');
    $page->assertPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();

    assertGoogleDriveImportForMediaSources($page, $api);
    expect($popup->script('document.documentElement.innerHTML.includes("server-drive-token")'))->toBeFalse();
});

test('opening the media sources menu preloads the picker sdk only', function () {
    enableOnlyGoogleDriveForMediaSources();
    config()->set('trypost.media_sources.google_drive.picker_sdk', 'data:text/javascript,window.__pickerLoaded=true');

    $page = openComposerForMediaSources($this);
    waitFor($page, '@composer-base-media-source-menu');

    expect($page->script('window.__pickerLoaded ?? false'))->toBeFalse();

    $page->click('@composer-base-media-source-menu');
    waitFor($page, '@media-source-google_drive');
    waitForCondition($page, 'window.__pickerLoaded === true');

    expect($page->script('window.__pickerLoaded ?? false'))->toBeTrue()
        ->and($page->script("[...document.scripts].some((script) => script.src.includes('accounts.google.com'))"))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
