<?php

declare(strict_types=1);

use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\TelescopeServiceProvider;
use App\Services\Media\Sources\GooglePhotosPicker;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\ClientRequestWatcher;

const GOOGLE_MEDIA_TEST_NONCE = 'composer-nonce-0123456789';

const GOOGLE_MEDIA_TEST_TOKEN = 'ya29.server-side-media-token';

const GOOGLE_MEDIA_TEST_REDIRECT = 'https://public.trypost.example/integrations/google/callback';

const GOOGLE_MEDIA_TEST_PHOTOS_SESSION = 'c3d4e5f6-a7b8-9012-cdef-123456789012';

const GOOGLE_MEDIA_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

const GOOGLE_MEDIA_PHOTOS_SCOPE = 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly';

beforeEach(function () {
    Storage::fake();

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

    config()->set([
        'services.google_media.client_id' => 'media-client-id',
        'services.google_media.client_secret' => 'media-client-secret',
        'services.google_media.redirect' => GOOGLE_MEDIA_TEST_REDIRECT,
        'services.google_media.api_key' => 'media-api-key',
        'services.google_media.app_id' => '123456789012',
        'trypost.media_sources.google_drive.enabled' => true,
        'trypost.media_sources.google_photos.enabled' => true,
        'trypost.media_sources.google_oauth.authorize_url' => 'https://accounts.google.test/o/oauth2/v2/auth',
        'trypost.media_sources.google_oauth.token_api' => 'https://oauth2.google.test',
    ]);

    fakePublicDns('142.250.0.10');
});

function googleMediaTokenUrl(): string
{
    return config('trypost.media_sources.google_oauth.token_api').'/token';
}

function googleMediaPhotosApi(): string
{
    return rtrim((string) config('trypost.media_sources.google_photos.api'), '/').'/v1';
}

/**
 * @param  array<string, mixed>|null  $token
 */
function fakeGoogleMediaApis(?array $token = null, int $tokenStatus = 200, int $sessionStatus = 200, string $pickerUri = 'https://photos.google.com/picker/'.GOOGLE_MEDIA_TEST_PHOTOS_SESSION): void
{
    $api = googleMediaPhotosApi();

    Http::fake([
        googleMediaTokenUrl() => Http::response($token ?? [
            'access_token' => GOOGLE_MEDIA_TEST_TOKEN,
            'expires_in' => 3599,
            'scope' => GOOGLE_MEDIA_DRIVE_SCOPE.' '.GOOGLE_MEDIA_PHOTOS_SCOPE,
            'token_type' => 'Bearer',
        ], $tokenStatus),
        "{$api}/sessions" => Http::response([
            'id' => GOOGLE_MEDIA_TEST_PHOTOS_SESSION,
            'pickerUri' => $pickerUri,
            'pollingConfig' => ['pollInterval' => '3.5s', 'timeoutIn' => '1800s'],
            'mediaItemsSet' => false,
        ], $sessionStatus),
        "{$api}/mediaItems*" => Http::response(['mediaItems' => [[
            'id' => 'item-1',
            'type' => 'PHOTO',
            'mediaFile' => ['baseUrl' => 'https://lh3.googleusercontent.com/photo-one', 'mimeType' => 'image/png', 'filename' => 'beach.png'],
        ]]]),
        "{$api}/sessions/*" => Http::response([]),
        config('trypost.media_sources.google_drive.api').'/files/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        'lh3.googleusercontent.com/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('unexpected', 500),
    ]);
}

/**
 * Runs the start route and returns the query Google would receive.
 *
 * @return array<string, string>
 */
function startGoogleMediaSignIn(User $user, string $source): array
{
    $location = (string) test()->actingAs($user)
        ->get(route('app.integrations.google.start', ['source' => $source, 'nonce' => GOOGLE_MEDIA_TEST_NONCE]))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

function googleMediaVerifierKey(User $user, string $state): string
{
    $stateHash = hash('sha256', $state);

    return "google-media-pkce:{$user->id}:{$stateHash}";
}

function googleMediaCallback(User $user, array $query): mixed
{
    return test()->actingAs($user)->get(route('app.integrations.google.callback', $query));
}

function assertGoogleMediaFailedPopup(mixed $response, string $source, ?string $nonce, string $messageKey): void
{
    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('source', $source)
            ->where('success', false)
            ->where('nonce', $nonce)
            ->where('pickerUri', null)
            ->where('message', __($messageKey)));
}

test('start redirects to the configured google authorize endpoint with the source scope, pkce, state and the configured redirect uri', function (string $source, string $scope) {
    Http::fake();

    $response = $this->actingAs($this->user)
        ->get(route('app.integrations.google.start', ['source' => $source, 'nonce' => GOOGLE_MEDIA_TEST_NONCE]))
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $pending = session('google_media_oauth');
    $cachedVerifier = Cache::get(googleMediaVerifierKey($this->user, (string) data_get($pending, 'state')));
    $verifier = Crypt::decryptString((string) $cachedVerifier);
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    expect(Str::before($location, '?'))->toBe('https://accounts.google.test/o/oauth2/v2/auth')
        ->and($query)->toBe([
            'client_id' => 'media-client-id',
            'redirect_uri' => GOOGLE_MEDIA_TEST_REDIRECT,
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'online',
            'state' => data_get($pending, 'state'),
            'code_challenge' => $expectedChallenge,
            'code_challenge_method' => 'S256',
        ])
        ->and($query['redirect_uri'])->not->toBe(route('app.integrations.google.callback'))
        ->and(strlen((string) data_get($pending, 'state')))->toBe(40)
        ->and($pending)->not->toHaveKey('verifier')
        ->and(json_encode(session()->all()))->not->toContain($verifier)
        ->and($cachedVerifier)->not->toContain($verifier)
        ->and(strlen($verifier))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128)
        ->and(data_get($pending, 'source'))->toBe($source)
        ->and(data_get($pending, 'nonce'))->toBe(GOOGLE_MEDIA_TEST_NONCE)
        ->and(data_get($pending, 'user_id'))->toBe($this->user->id)
        ->and(data_get($pending, 'workspace_id'))->toBe($this->workspace->id);

    Http::assertNothingSent();
})->with([
    'drive' => ['google_drive', GOOGLE_MEDIA_DRIVE_SCOPE],
    'photos' => ['google_photos', GOOGLE_MEDIA_PHOTOS_SCOPE],
]);

test('start rejects an unknown or disabled source and a malformed nonce', function (array $query, string $error) {
    config()->set('trypost.media_sources.google_photos.enabled', false);

    $this->actingAs($this->user)
        ->getJson(route('app.integrations.google.start', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(session('google_media_oauth'))->toBeNull();
})->with([
    'disabled source' => [['source' => 'google_photos', 'nonce' => GOOGLE_MEDIA_TEST_NONCE], 'source'],
    'not a google source' => [['source' => 'canva', 'nonce' => GOOGLE_MEDIA_TEST_NONCE], 'source'],
    'no nonce' => [['source' => 'google_drive'], 'nonce'],
    'short nonce' => [['source' => 'google_drive', 'nonce' => 'short'], 'nonce'],
    'unsafe nonce' => [['source' => 'google_drive', 'nonce' => 'composer nonce/../0123456'], 'nonce'],
]);

test('a user outside the workspace cannot start, finish or claim a google sign-in', function () {
    Http::fake();
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())
        ->get(route('app.integrations.google.start', ['source' => 'google_drive', 'nonce' => GOOGLE_MEDIA_TEST_NONCE]))
        ->assertForbidden();

    $this->actingAs($outsider->fresh())
        ->get(route('app.integrations.google.callback', ['code' => 'auth-code', 'state' => 'state']))
        ->assertForbidden();

    $this->actingAs($outsider->fresh())
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertForbidden();

    Http::assertNothingSent();
});

test('a callback whose state does not match renders a failed popup and exchanges nothing', function () {
    fakeGoogleMediaApis();
    startGoogleMediaSignIn($this->user, 'google_drive');

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => str_repeat('x', 40)]);

    assertGoogleMediaFailedPopup($response, 'google_drive', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
    expect(session('google_media_oauth'))->toBeNull()
        ->and(Cache::has("google-media-return:{$this->user->id}:".GOOGLE_MEDIA_TEST_NONCE))->toBeFalse();
    Http::assertNothingSent();
});

test('a replayed callback is refused: the pending sign-in is single use', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $replay = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($replay, 'google_drive', null, 'posts.composer.media_sources.errors.google_connect_failed');
    Http::assertSentCount(1);
});

test('a callback is refused for another user or after switching workspace', function (string $case) {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    $other = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($other->id, membershipPivot('member'));
    $other->update(['current_workspace_id' => $this->workspace->id]);

    if ($case === 'other workspace') {
        $second = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);
        $second->members()->attach($this->user->id, membershipPivot('member'));
        $this->user->update(['current_workspace_id' => $second->id]);
    }

    $response = googleMediaCallback($case === 'other user' ? $other : $this->user->fresh(), ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_drive', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
    Http::assertNothingSent();
})->with(['other user', 'other workspace']);

test('a denied consent renders a failed popup that says why', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_photos');

    $response = googleMediaCallback($this->user, ['error' => 'access_denied', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_photos', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.scope_not_granted');
    Http::assertNothingSent();
});

test('a token without the source scope is refused and nothing is kept', function (string $source, string $grantedScope) {
    fakeGoogleMediaApis(['access_token' => GOOGLE_MEDIA_TEST_TOKEN, 'expires_in' => 3599, 'scope' => $grantedScope]);
    $query = startGoogleMediaSignIn($this->user, $source);

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, $source, GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.scope_not_granted');
    expect(Cache::has("google-media-return:{$this->user->id}:".GOOGLE_MEDIA_TEST_NONCE))->toBeFalse()
        ->and(GooglePhotosPicker::token($this->user->id, GOOGLE_MEDIA_TEST_PHOTOS_SESSION))->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'photospicker'));
})->with([
    'drive without drive.file' => ['google_drive', 'openid email'],
    'photos with only the drive scope' => ['google_photos', GOOGLE_MEDIA_DRIVE_SCOPE],
]);

test('a failed code exchange renders a failed popup', function () {
    fakeGoogleMediaApis(['error' => 'invalid_grant'], tokenStatus: 400);
    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_drive', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
});

test('a source switched off between start and callback is refused', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_photos');
    config()->set('trypost.media_sources.google_photos.enabled', false);

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_photos', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
    Http::assertNothingSent();
});

test('drive: the code is exchanged server-side and the composer claims the token once', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');
    $verifier = Crypt::decryptString((string) Cache::get(googleMediaVerifierKey($this->user, $query['state'])));

    $popup = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('source', 'google_drive')
            ->where('success', true)
            ->where('nonce', GOOGLE_MEDIA_TEST_NONCE)
            ->where('pickerUri', null)
            ->where('message', null));

    expect($popup->getContent())->not->toContain(GOOGLE_MEDIA_TEST_TOKEN);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === googleMediaTokenUrl()
        && $request->isForm()
        && $request->data() === [
            'grant_type' => 'authorization_code',
            'code' => 'auth-code',
            'code_verifier' => $verifier,
            'client_id' => 'media-client-id',
            'client_secret' => 'media-client-secret',
            'redirect_uri' => GOOGLE_MEDIA_TEST_REDIRECT,
        ]);

    $stored = Cache::get("google-media-return:{$this->user->id}:".GOOGLE_MEDIA_TEST_NONCE);
    expect(serialize($stored))->not->toContain(GOOGLE_MEDIA_TEST_TOKEN);

    $other = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($other->id, membershipPivot('member'));
    $other->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($other)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertNotFound();

    $claim = $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertOk()
        ->assertJsonPath('source', 'google_drive')
        ->assertJsonPath('access_token', GOOGLE_MEDIA_TEST_TOKEN)
        ->assertJsonMissingPath('session_id');

    expect($claim->json('expires_in'))->toBeGreaterThan(3500)->toBeLessThanOrEqual(3599)
        ->and($claim->headers->get('Cache-Control'))->toContain('no-store');

    $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertNotFound();
});

test('drive: an unclaimed token is gone after ten minutes', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');
    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    $this->travel(601)->seconds();

    $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertNotFound();
});

test('photos: the callback opens the picker session with the token it keeps and hands the composer only the session', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_photos');

    $popup = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('source', 'google_photos')
            ->where('success', true)
            ->where('nonce', GOOGLE_MEDIA_TEST_NONCE)
            ->where('pickerUri', 'https://photos.google.com/picker/'.GOOGLE_MEDIA_TEST_PHOTOS_SESSION));

    expect($popup->getContent())->not->toContain(GOOGLE_MEDIA_TEST_TOKEN)
        ->and(GooglePhotosPicker::token($this->user->id, GOOGLE_MEDIA_TEST_PHOTOS_SESSION))->toBe(GOOGLE_MEDIA_TEST_TOKEN);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === googleMediaPhotosApi().'/sessions'
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_MEDIA_TEST_TOKEN)
        && $request->data() === ['pickingConfig' => ['maxItemCount' => 10]]);

    $claim = $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertOk()
        ->assertExactJson([
            'source' => 'google_photos',
            'session_id' => GOOGLE_MEDIA_TEST_PHOTOS_SESSION,
            'polling' => ['interval_ms' => 3500, 'timeout_ms' => 1800000],
        ]);

    expect($claim->getContent())->not->toContain(GOOGLE_MEDIA_TEST_TOKEN);
});

test('photos: the picker session lets the user pick as many items as configured, never more than a tray holds', function (int $configured, int $sent) {
    fakeGoogleMediaApis();
    config()->set('trypost.media_sources.google_photos.max_items', $configured);
    $query = startGoogleMediaSignIn($this->user, 'google_photos');

    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === googleMediaPhotosApi().'/sessions'
        && $request->data() === ['pickingConfig' => ['maxItemCount' => $sent]]);
})->with([
    'configured' => [4, 4],
    'above the tray limit' => [50, 10],
    'zero' => [0, 1],
]);

test('photos: a picker api failure or a non-https picker uri renders a failed popup and keeps no token', function (int $sessionStatus, string $pickerUri) {
    fakeGoogleMediaApis(sessionStatus: $sessionStatus, pickerUri: $pickerUri);
    $query = startGoogleMediaSignIn($this->user, 'google_photos');

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_photos', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
    expect(GooglePhotosPicker::token($this->user->id, GOOGLE_MEDIA_TEST_PHOTOS_SESSION))->toBeNull()
        ->and(Cache::has("google-media-return:{$this->user->id}:".GOOGLE_MEDIA_TEST_NONCE))->toBeFalse();
})->with([
    'api error' => [401, 'https://photos.google.com/picker/x'],
    'plain http picker uri' => [200, 'http://photos.google.com/picker/x'],
    'javascript picker uri' => [200, 'javascript:alert(1)'],
]);

test('the token is in no queued payload, database table or plaintext cache write', function (string $source) {
    fakeGoogleMediaApis();
    config()->set('queue.default', 'database');

    $cacheWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cacheWrites): void {
        $cacheWrites[] = serialize($event->value);
    });

    $query = startGoogleMediaSignIn($this->user, $source);
    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])->assertOk();
    $claim = $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertOk();

    $payload = $source === 'google_drive'
        ? ['source' => 'google_drive', 'access_token' => $claim->json('access_token'), 'file' => ['id' => '1AbC_dE-fGh', 'name' => 'beach.png']]
        : ['source' => 'google_photos', 'session_id' => $claim->json('session_id')];

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), $payload)
        ->assertAccepted();

    expect(DB::table('jobs')->where('queue', ImportRemoteMedia::QUEUE)->count())->toBe(1)
        ->and(implode("\n", $cacheWrites))->not->toContain(GOOGLE_MEDIA_TEST_TOKEN);

    $rows = collect(Schema::getTableListing(Schema::getCurrentSchemaListing()))
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE))
        ->implode("\n");

    expect($rows)->not->toContain(GOOGLE_MEDIA_TEST_TOKEN);
})->with(['google_drive', 'google_photos']);

test('the claim route is throttled', function () {
    foreach (range(1, 60) as $attempt) {
        $this->actingAs($this->user)
            ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
            ->assertNotFound();
    }

    $this->actingAs($this->user)
        ->postJson(route('app.integrations.google.returns.claim', GOOGLE_MEDIA_TEST_NONCE))
        ->assertTooManyRequests();
});

test('telescope masks the google token exchange secrets in recorded client requests', function () {
    $hiddenParameters = Telescope::$hiddenRequestParameters;
    $hiddenResponseParameters = Telescope::$hiddenResponseParameters;
    $hiddenHeaders = Telescope::$hiddenRequestHeaders;
    $filters = Telescope::$filterUsing;

    try {
        (new TelescopeServiceProvider(app()))->register();

        $watcher = new class extends ClientRequestWatcher
        {
            /**
             * @return array<string, mixed>
             */
            public function recordedPayload(Request $request): array
            {
                return $this->payload($this->input($request));
            }

            public function recordedResponse(ClientResponse $response): mixed
            {
                return $this->response($response);
            }
        };

        $request = new Request(new GuzzleRequest('POST', googleMediaTokenUrl(), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => 'secret-code',
            'code_verifier' => 'secret-verifier',
            'client_id' => 'media-client-id',
            'client_secret' => 'secret-client',
            'redirect_uri' => GOOGLE_MEDIA_TEST_REDIRECT,
        ])));
        $response = new ClientResponse(new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-refresh',
            'expires_in' => 3599,
            'scope' => GOOGLE_MEDIA_DRIVE_SCOPE,
        ])));

        expect($watcher->recordedPayload($request))->toBe([
            'grant_type' => 'authorization_code',
            'code' => '********',
            'code_verifier' => '********',
            'client_id' => 'media-client-id',
            'client_secret' => '********',
            'redirect_uri' => GOOGLE_MEDIA_TEST_REDIRECT,
        ])->and($watcher->recordedResponse($response))->toBe([
            'access_token' => '********',
            'refresh_token' => '********',
            'expires_in' => 3599,
            'scope' => GOOGLE_MEDIA_DRIVE_SCOPE,
        ]);
    } finally {
        Telescope::$hiddenRequestParameters = $hiddenParameters;
        Telescope::$hiddenResponseParameters = $hiddenResponseParameters;
        Telescope::$hiddenRequestHeaders = $hiddenHeaders;
        Telescope::$filterUsing = $filters;
    }
});

test('the pkce verifier is single use and a callback without it exchanges nothing', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    Cache::forget(googleMediaVerifierKey($this->user, $query['state']));

    $response = googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']]);

    assertGoogleMediaFailedPopup($response, 'google_drive', GOOGLE_MEDIA_TEST_NONCE, 'posts.composer.media_sources.errors.google_connect_failed');
    Http::assertNothingSent();

    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => $query['state']])
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    expect(Cache::has(googleMediaVerifierKey($this->user, $query['state'])))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->url() === googleMediaTokenUrl()
        && data_get($request->data(), 'code_verifier') !== null);
});

test('the verifier of a refused callback is dropped', function () {
    fakeGoogleMediaApis();
    $query = startGoogleMediaSignIn($this->user, 'google_drive');

    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => str_repeat('x', 40)]);

    expect(Cache::has(googleMediaVerifierKey($this->user, $query['state'])))->toBeFalse();
});

test('the callback is throttled per user', function () {
    fakeGoogleMediaApis();
    config()->set('trypost.media_sources.imports_per_user_per_minute', 2);

    foreach (range(1, 2) as $attempt) {
        googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => str_repeat('x', 40)])->assertOk();
    }

    googleMediaCallback($this->user, ['code' => 'auth-code', 'state' => str_repeat('x', 40)])->assertTooManyRequests();
});
