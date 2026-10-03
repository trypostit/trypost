<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Poll from the page until `$condition` holds (never sleep(): the test server
 * only ticks while Pest awaits Playwright).
 */
function waitForComposerMediaSourcesCondition(mixed $page, string $condition): void
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

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForComposerMediaSourcesTestId(mixed $page, string $testId, int $rounds = 1): void
{
    $visible = "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0";

    for ($round = 0; $round < $rounds && ! $page->script("Boolean({$visible})"); $round++) {
        waitForComposerMediaSourcesCondition($page, $visible);
    }
}

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
    waitForComposerMediaSourcesTestId($page, 'composer-base-toolbar');

    return $page;
}

test('with no media source enabled the split button is not rendered', function () {
    config()->set([
        'services.unsplash.access_key' => null,
        'services.google_media.client_id' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
    ]);

    $page = openComposerForMediaSources($this);

    $page->assertPresent('@composer-base-emoji')
        ->assertNotPresent('@composer-base-add-media')
        ->assertNotPresent('@composer-base-media-source-menu')
        ->assertNotPresent('@composer-base-media-source-main')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('[data-testid$=\"-media-source-menu\"]').length"))->toBe(0);
});

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

test('the split button starts on the menu and then remembers the last used source', function () {
    enableOnlyUnsplashForMediaSources();
    config()->set([
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.google_drive.enabled' => false,
        'trypost.media_sources.google_photos.enabled' => false,
    ]);

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');

    $page->assertNotPresent('@composer-base-add-media')
        ->assertAttribute('@composer-base-media-source-main', 'data-source', 'none')
        ->assertAttribute('@composer-base-media-source-main', 'aria-label', 'More media sources');

    $page->click('@composer-base-media-source-main');
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-list');

    expect($page->script("Array.from(document.querySelectorAll('[data-testid=\"composer-base-media-source-list\"] [data-testid^=\"media-source-\"]')).map((item) => item.dataset.testid)"))
        ->toBe(['media-source-canva', 'media-source-unsplash']);

    $page->click('@media-source-unsplash');
    waitForComposerMediaSourcesTestId($page, 'unsplash-dialog');

    $page->assertAttribute('@composer-base-media-source-main', 'data-source', 'unsplash')
        ->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Unsplash');

    $page->keys('@unsplash-dialog', 'Escape');
    waitForComposerMediaSourcesCondition($page, "!document.querySelector('[data-testid=\"unsplash-dialog\"]')");

    $page->click('@composer-base-media-source-menu');
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-list');

    $page->assertPresent('@media-source-canva')
        ->assertPresent('@media-source-unsplash')
        ->assertNotPresent('@media-source-google_drive')
        ->assertNotPresent('@media-source-google_photos')
        ->assertNoJavaScriptErrors();
});

test('with only unsplash enabled the split button selects from unsplash', function () {
    enableOnlyUnsplashForMediaSources();

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');

    $page->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Unsplash')
        ->assertNoJavaScriptErrors();
});

test('picking an unsplash photo from the menu adds a tile and credits the photographer', function () {
    enableOnlyUnsplashForMediaSources();

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-menu');
    $page->click('@composer-base-media-source-menu');
    waitForComposerMediaSourcesTestId($page, 'media-source-unsplash');
    $page->click('@media-source-unsplash');
    waitForComposerMediaSourcesTestId($page, 'unsplash-dialog');
    waitForComposerMediaSourcesTestId($page, 'unsplash-photo-0');

    $page->assertSeeIn('@unsplash-dialog', 'Ana Photographer');
    expect($page->script("document.querySelector('[data-testid=\"unsplash-dialog\"] a[href*=\"@sunrise\"]')?.getAttribute('href')"))
        ->toEndWith('@sunrise?utm_source=trypost&utm_medium=referral');

    $page->click('@unsplash-photo-0');
    waitForComposerMediaSourcesTestId($page, 'composer-media-item');
    waitForComposerMediaSourcesCondition($page, "!document.querySelector('[data-testid=\"unsplash-dialog\"]')");

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
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    $page->click('@composer-base-media-source-main');
    waitForComposerMediaSourcesTestId($page, 'unsplash-photo-1');

    $page->assertSeeIn('@unsplash-dialog', 'Bruno Photographer')
        ->assertNotPresent('@unsplash-photo-2')
        ->assertNotPresent('@unsplash-error')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"unsplash-dialog\"] [data-slot=\"dialog-footer\"]') === null"))->toBeTrue();
});

test('an unsplash outage shows an error instead of an empty result', function () {
    enableOnlyUnsplashForMediaSources(apiStatus: 403);

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    $page->click('@composer-base-media-source-main');
    waitForComposerMediaSourcesTestId($page, 'unsplash-error');

    $page->assertSeeIn('@unsplash-error', "Unsplash isn't available right now.")
        ->assertNotPresent('@unsplash-empty')
        ->assertNoJavaScriptErrors();
});

test('the idea editor picks an unsplash photo into its tray', function () {
    enableOnlyUnsplashForMediaSources();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.create'));
    waitForComposerMediaSourcesTestId($page, 'idea-editor-media-source-main');
    $page->click('@idea-editor-media-source-main');
    waitForComposerMediaSourcesTestId($page, 'unsplash-photo-0');
    $page->click('@unsplash-photo-0');
    waitForComposerMediaSourcesTestId($page, 'idea-media-item');

    $page->assertPresent('@idea-media-item')
        ->assertNoJavaScriptErrors();
});

function enableOnlyGoogleDriveForMediaSources(): string
{
    config()->set([
        'services.unsplash.access_key' => null,
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

test('picking a google drive file adds a tile to the shared tray', function () {
    $api = enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    $page->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Google Drive');

    stubGoogleDriveForMediaSources($page);
    $page->click('@composer-base-media-source-main');
    finishGoogleDriveSignIn($page);
    waitForComposerMediaSourcesTestId($page, 'composer-media-item');

    $page->assertPresent('@composer-media-item')
        ->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Google Drive')
        ->assertNoJavaScriptErrors();

    assertGoogleDriveImportForMediaSources($page, $api);
    expect(Cache::has('google-media-return:'.User::query()->sole()->id.':'.googleDriveSignInNonce($page)))->toBeFalse();
});

test('picking a google drive file in a single channel adds a tile to that channel tray', function () {
    $api = enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    $account = SocialAccount::query()->sole();
    waitForComposerMediaSourcesTestId($page, 'composer-add-account');
    $page->click('@composer-add-account');
    $page->click("@composer-account-option-{$account->id}");
    waitForComposerMediaSourcesTestId($page, "composer-{$account->id}-media-source-menu");
    $page->fill("@composer-caption-{$account->id}", 'Drive pick for one channel');

    stubGoogleDriveForMediaSources($page);
    $page->click("@composer-{$account->id}-media-source-menu");
    waitForComposerMediaSourcesTestId($page, 'media-source-google_drive');
    $page->click('@media-source-google_drive');
    finishGoogleDriveSignIn($page);
    waitForComposerMediaSourcesTestId($page, "composer-{$account->id}-media-item");

    $page->assertPresent("@composer-{$account->id}-media-item")
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelectorAll('[data-testid=\"composer-media-item\"]').length"))->toBe(0);

    assertGoogleDriveImportForMediaSources($page, $api);
});

test('the drive picker can be clicked, focused and typed in over the composer dialog', function () {
    $api = enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    stubGoogleDriveForMediaSources($page, holdOpen: true);
    $page->click('@composer-base-media-source-main');
    finishGoogleDriveSignIn($page);
    waitForComposerMediaSourcesTestId($page, 'drive-picker-search');
    waitForComposerMediaSourcesCondition($page, "document.activeElement?.dataset?.testid === 'drive-picker-search'");

    expect($page->script('document.activeElement?.dataset?.testid ?? null'))->toBe('drive-picker-search')
        ->and($page->script('getComputedStyle(document.body).pointerEvents'))->not->toBe('none');

    $page->click('@drive-picker-search');
    $page->type('@drive-picker-search', 'beach');

    expect($page->script('document.activeElement?.dataset?.testid ?? null'))->toBe('drive-picker-search')
        ->and($page->script("document.querySelector('[data-testid=\"drive-picker-search\"]').value"))->toBe('beach');
    $page->assertPresent('@post-composer-dialog');

    $page->script('window.__drive.finish()');
    waitForComposerMediaSourcesTestId($page, 'composer-media-item');

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
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    stubGoogleDriveForMediaSources($page);
    $page->click('@composer-base-media-source-main');
    $nonce = googleDriveSignInNonce($page);
    $message = __('posts.composer.media_sources.errors.scope_not_granted');

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'google_drive', nonce: '{$nonce}', success: false, message: '{$message}' },
        }));
        true;
    JS);
    waitForComposerMediaSourcesCondition($page, "document.body.innerText.includes('TryPost needs access to the file you pick')");

    $page->assertSee($message)
        ->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect($page->script('window.__drive.pickerToken'))->toBeNull()
        ->and($page->script("window.__drive.finished.some((request) => request.includes('/integrations/google/returns/'))"))->toBeFalse()
        ->and(Media::query()->count())->toBe(0);
});

test('a blocked google drive popup says so and asks the server for nothing', function () {
    enableOnlyGoogleDriveForMediaSources();

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    stubGoogleDriveForMediaSources($page);
    $page->script('window.open = () => null; true;');
    $page->click('@composer-base-media-source-main');
    waitForComposerMediaSourcesCondition($page, "document.body.innerText.includes('Your browser blocked the window')");

    $page->assertSee(__('posts.composer.media_sources.errors.popup_blocked'))
        ->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect($page->script('window.__drive.finished.length'))->toBe(0);
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
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-main');
    stubGoogleDriveForMediaSources($page);
    $page->script('window.open = (url) => { window.__drive.opened.push(url); window.__drive.openedInClick = window.__drive.inClick; return { closed: true, close() {} }; }; true;');
    $page->click('@composer-base-media-source-main');
    $opened = $page->script('window.__drive.opened[0]');

    $popup = visit($opened);
    waitForComposerMediaSourcesTestId($popup, 'media-source-popup');
    $popup->assertAttribute('@media-source-popup', 'data-success', 'true')
        ->assertSee(__('integrations.media_source_popup.google_drive_ready'));

    waitForComposerMediaSourcesTestId($page, 'composer-media-item', rounds: 4);
    $page->assertPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();

    assertGoogleDriveImportForMediaSources($page, $api);
    expect($popup->script('document.documentElement.innerHTML.includes("server-drive-token")'))->toBeFalse();
});

test('opening the media sources menu preloads the picker sdk only', function () {
    enableOnlyGoogleDriveForMediaSources();
    config()->set('trypost.media_sources.google_drive.picker_sdk', 'data:text/javascript,window.__pickerLoaded=true');

    $page = openComposerForMediaSources($this);
    waitForComposerMediaSourcesTestId($page, 'composer-base-media-source-menu');

    expect($page->script('window.__pickerLoaded ?? false'))->toBeFalse();

    $page->click('@composer-base-media-source-menu');
    waitForComposerMediaSourcesTestId($page, 'media-source-google_drive');
    waitForComposerMediaSourcesCondition($page, 'window.__pickerLoaded === true');

    expect($page->script('window.__pickerLoaded ?? false'))->toBeTrue()
        ->and($page->script("[...document.scripts].some((script) => script.src.includes('accounts.google.com'))"))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
