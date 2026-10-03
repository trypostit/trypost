<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\Services\Media\Sources\GooglePhotosPicker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const COMPOSER_GOOGLE_PHOTOS_SESSION = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';

const COMPOSER_GOOGLE_PHOTOS_SCOPE = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';

/**
 * An https picker on a closed local port: the popup's navigation fails at
 * once instead of reaching Google.
 */
const COMPOSER_GOOGLE_PHOTOS_PICKER_URI = 'https://127.0.0.1:9/picker/'.COMPOSER_GOOGLE_PHOTOS_SESSION;

const COMPOSER_GOOGLE_PHOTOS_NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

/**
 * Poll from the page until `$condition` holds (never sleep(): the test server
 * only ticks while Pest awaits Playwright).
 */
function waitForComposerGooglePhotosCondition(mixed $page, string $condition): void
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

function waitForComposerGooglePhotosTestId(mixed $page, string $testId, int $rounds = 1): void
{
    $visible = "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0";

    for ($round = 0; $round < $rounds && ! $page->script("Boolean({$visible})"); $round++) {
        waitForComposerGooglePhotosCondition($page, $visible);
    }
}

function openComposerForGooglePhotos(mixed $test): mixed
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $test->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerGooglePhotosTestId($page, 'composer-base-media-source-main', rounds: 4);

    return $page;
}

function enableOnlyGooglePhotosForComposer(bool $mediaItemsSet = true): string
{
    config()->set([
        'services.unsplash.access_key' => null,
        'services.google_media.client_id' => 'media-client-id',
        'services.google_media.client_secret' => 'media-client-secret',
        'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback',
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
        'trypost.media_sources.google_drive.enabled' => false,
        'trypost.media_sources.google_photos.enabled' => true,
        'trypost.media_sources.google_oauth.authorize_url' => route('app.integrations.google.callback').'?code=browser-code&ignored',
        'trypost.media_sources.google_oauth.token_api' => 'https://oauth2.google.test',
    ]);

    test()->mock(HostResolver::class)->shouldReceive('addresses')->andReturn(['142.250.0.10']);

    $api = rtrim((string) config('trypost.media_sources.google_photos.api'), '/').'/v1';

    Http::fake([
        'https://oauth2.google.test/token' => Http::response([
            'access_token' => 'server-photos-token',
            'expires_in' => 3599,
            'scope' => COMPOSER_GOOGLE_PHOTOS_SCOPE,
        ]),
        "{$api}/sessions/*" => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response([], 200)
            : Http::response([
                'id' => COMPOSER_GOOGLE_PHOTOS_SESSION,
                'mediaItemsSet' => $mediaItemsSet,
                'pollingConfig' => ['pollInterval' => '1s', 'timeoutIn' => '60s'],
            ]),
        "{$api}/sessions" => Http::response([
            'id' => COMPOSER_GOOGLE_PHOTOS_SESSION,
            'pickerUri' => COMPOSER_GOOGLE_PHOTOS_PICKER_URI,
            'pollingConfig' => ['pollInterval' => '1s', 'timeoutIn' => '60s'],
            'mediaItemsSet' => false,
        ]),
        "{$api}/mediaItems*" => Http::response(['mediaItems' => [
            [
                'id' => 'photo-item-1',
                'type' => 'PHOTO',
                'mediaFile' => [
                    'baseUrl' => 'https://lh3.googleusercontent.com/composer-photo',
                    'mimeType' => 'image/png',
                    'filename' => 'beach.png',
                ],
            ],
            [
                'id' => 'photo-item-2',
                'type' => 'PHOTO',
                'mediaFile' => [
                    'baseUrl' => 'https://lh3.googleusercontent.com/composer-logo',
                    'mimeType' => 'image/png',
                    'filename' => 'logo.png',
                ],
            ],
        ]]),
        'lh3.googleusercontent.com/composer-photo=d' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        'lh3.googleusercontent.com/composer-logo=d' => Http::response(file_get_contents(base_path('tests/fixtures/blue-logo.png')), 200, ['Content-Type' => 'image/png']),
    ]);

    return $api;
}

/**
 * A stand-in for the sign-in popup that records where it was opened and
 * whether inside the click; `$severed` makes it read closed from the start,
 * like a popup a provider's COOP cut off. A pending import tile is recorded
 * as soon as it mounts, and every finished XHR as `METHOD url`.
 */
function stubGooglePhotosPopupForComposer(mixed $page, bool $severed = false): void
{
    $closed = $severed ? 'true' : 'false';

    $page->script(<<<JS
        (() => {
            window.__photos = { opened: [], openedInClick: null, inClick: false, popup: null, sawPending: false, finished: [] };
            const xhrOpen = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.addEventListener('loadend', () => window.__photos.finished.push(`\${String(method).toUpperCase()} \${url}`));
                return xhrOpen.call(this, method, url, ...rest);
            };
            window.addEventListener('click', () => { window.__photos.inClick = true; }, true);
            window.addEventListener('click', () => { window.__photos.inClick = false; });
            new MutationObserver(() => {
                if (document.querySelector('[data-testid="composer-import-item"]')) {
                    window.__photos.sawPending = true;
                }
            }).observe(document.body, { childList: true, subtree: true });
            window.open = (url) => {
                window.__photos.opened.push(url);
                window.__photos.openedInClick = window.__photos.inClick;
                window.__photos.popup = { closed: {$closed}, close() { this.closed = true; } };
                return window.__photos.popup;
            };
        })();
    JS);
}

function googlePhotosSignInUrl(mixed $page): string
{
    $opened = $page->script('window.__photos.opened');
    parse_str((string) parse_url($opened[0], PHP_URL_QUERY), $query);

    expect($opened)->toHaveCount(1)
        ->and(parse_url($opened[0], PHP_URL_PATH))->toBe(route('app.integrations.google.start', [], false))
        ->and(data_get($query, 'source'))->toBe('google_photos')
        ->and(data_get($query, 'nonce'))->toMatch(COMPOSER_GOOGLE_PHOTOS_NONCE_PATTERN)
        ->and($page->script('window.__photos.openedInClick'))->toBeTrue();

    return $opened[0];
}

test('the real sign-in popup opens the picker and every picked photo becomes its own tile, in order', function () {
    $api = enableOnlyGooglePhotosForComposer();

    $page = openComposerForGooglePhotos($this);
    $page->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Google Photos');

    stubGooglePhotosPopupForComposer($page, severed: true);
    $page->click('@composer-base-media-source-main');

    $popup = visit(googlePhotosSignInUrl($page));

    expect((string) rescue(fn (): string => $popup->content(), '', report: false))->not->toContain('server-photos-token');

    foreach (range(1, 4) as $round) {
        waitForComposerGooglePhotosCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 2");
    }
    $page->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('[data-testid=\"composer-media-item\"]').length"))->toBe(2)
        ->and($page->script('window.__photos.sawPending'))->toBeTrue()
        ->and($page->script("window.__photos.finished.some((request) => request.startsWith('DELETE '))"))->toBeFalse();

    $media = Media::query()->orderBy('created_at')->get();
    expect($media)->toHaveCount(2)
        ->and($media->pluck('collection')->unique()->all())->toBe([Media::COLLECTION_UPLOADS])
        ->and($media->every(fn (Media $item): bool => data_get($item->meta, 'source') === Source::GooglePhotos->value))->toBeTrue()
        ->and($media->map(fn (Media $item): string => data_get($item->meta, 'source_meta.media_item_id'))->sort()->values()->all())->toBe(['photo-item-1', 'photo-item-2']);

    $tileImages = $page->script("Array.from(document.querySelectorAll('[data-testid=\"composer-media-item\"] img')).map((image) => image.getAttribute('src'))");
    $byItem = $media->mapWithKeys(fn (Media $item): array => [data_get($item->meta, 'source_meta.media_item_id') => $item->url]);

    expect($tileImages)->toHaveCount(2)
        ->and($tileImages[0])->toContain((string) parse_url((string) $byItem['photo-item-1'], PHP_URL_PATH))
        ->and($tileImages[1])->toContain((string) parse_url((string) $byItem['photo-item-2'], PHP_URL_PATH));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === "{$api}/sessions"
        && $request->hasHeader('Authorization', 'Bearer server-photos-token')
        && $request->data() === ['pickingConfig' => ['maxItemCount' => (int) config('trypost.media_sources.google_photos.max_items')]]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/composer-photo=d');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/composer-logo=d');
});

test('closing the google photos window without picking adds nothing and discards the session', function () {
    $api = enableOnlyGooglePhotosForComposer(mediaItemsSet: false);

    $page = openComposerForGooglePhotos($this);
    stubGooglePhotosPopupForComposer($page);
    $user = User::query()->sole();

    $page->click('@composer-base-media-source-main');
    parse_str((string) parse_url(googlePhotosSignInUrl($page), PHP_URL_QUERY), $query);
    $nonce = (string) data_get($query, 'nonce');

    GooglePhotosPicker::rememberToken($user->id, COMPOSER_GOOGLE_PHOTOS_SESSION, 'server-photos-token', 3599);
    Cache::put("google-media-return:{$user->id}:{$nonce}", [
        'user_id' => $user->id,
        'workspace_id' => $user->current_workspace_id,
        'source' => 'google_photos',
        'session_id' => COMPOSER_GOOGLE_PHOTOS_SESSION,
        'polling' => ['interval_ms' => 1000, 'timeout_ms' => 60000],
    ], 600);

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'google_photos', nonce: '{$nonce}', success: true, import_id: null, replaces: null, message: null },
        }));
        true;
    JS);
    waitForComposerGooglePhotosCondition($page, "window.__photos.finished.some((request) => request.includes('/integrations/google/returns/'))");
    $page->script('window.__photos.popup.closed = true; true;');
    waitForComposerGooglePhotosCondition($page, "window.__photos.finished.some((request) => request.startsWith('DELETE ') && request.includes('/media/google-photos/sessions/'))");

    $page->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();

    expect(Media::query()->count())->toBe(0)
        ->and(Cache::has(GooglePhotosPicker::cacheKey($user->id, COMPOSER_GOOGLE_PHOTOS_SESSION)))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === "{$api}/sessions/".COMPOSER_GOOGLE_PHOTOS_SESSION);
});

test('a refused google photos sign-in opens no picker and says why', function () {
    enableOnlyGooglePhotosForComposer();

    $page = openComposerForGooglePhotos($this);
    stubGooglePhotosPopupForComposer($page, severed: true);
    $page->click('@composer-base-media-source-main');

    config()->set('trypost.media_sources.google_oauth.authorize_url', route('app.integrations.google.callback').'?error=access_denied&ignored');

    $popup = visit(googlePhotosSignInUrl($page));
    waitForComposerGooglePhotosTestId($popup, 'media-source-popup');
    $popup->assertAttribute('@media-source-popup', 'data-success', 'false')
        ->assertSee(__('posts.composer.media_sources.errors.scope_not_granted'));

    parse_str((string) parse_url(googlePhotosSignInUrl($page), PHP_URL_QUERY), $query);
    $nonce = (string) data_get($query, 'nonce');
    $message = __('posts.composer.media_sources.errors.scope_not_granted');
    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'google_photos', nonce: '{$nonce}', success: false, message: '{$message}' },
        }));
        true;
    JS);
    waitForComposerGooglePhotosCondition($page, "document.body.innerText.includes('Try again and allow it')");

    $page->assertSee(__('posts.composer.media_sources.errors.scope_not_granted'))
        ->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect(Media::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'photospicker'));
});

test('a blocked google photos popup says so and asks the server for nothing', function () {
    enableOnlyGooglePhotosForComposer();

    $page = openComposerForGooglePhotos($this);
    stubGooglePhotosPopupForComposer($page);
    $page->script('window.open = () => null; true;');

    $page->click('@composer-base-media-source-main');
    waitForComposerGooglePhotosCondition($page, "document.body.innerText.includes('Your browser blocked the window')");

    $page->assertSee(__('posts.composer.media_sources.errors.popup_blocked'))
        ->assertNotPresent('@composer-media-item')
        ->assertNoJavaScriptErrors();
    expect($page->script('window.__photos.finished.length'))->toBe(0);
    Http::assertNothingSent();
});
