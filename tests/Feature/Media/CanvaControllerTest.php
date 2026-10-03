<?php

declare(strict_types=1);

use App\Enums\Media\CanvaPreset;
use App\Enums\Media\Source;
use App\Jobs\Media\ExportCanvaDesign;
use App\Models\Account;
use App\Models\MediaSourceConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\TelescopeServiceProvider;
use App\Support\MediaImportStatus;
use Firebase\JWT\JWT;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\Watchers\ClientRequestWatcher;

const CANVA_TEST_NONCE = 'composer-nonce-0123456789';

beforeEach(function () {
    Queue::fake();

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
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.canva.api' => 'https://api.canva.test/rest/v1',
        'trypost.media_sources.canva.authorize_url' => 'https://www.canva.test/api/oauth/authorize',
        'trypost.media_sources.canva.editor_hosts' => '*.canva.test',
    ]);

    $this->signingKey = canvaControllerSigningKey();
});

/**
 * An Ed25519 key shaped like the ones Canva serves from /connect/keys:
 * `kty` OKP, no `alg`. `pem` holds the base64 secret key JWT::encode signs with.
 *
 * @return array{pem: string, jwk: array<string, string>}
 */
function canvaControllerSigningKey(string $kid = 'canva-kid-1'): array
{
    $keyPair = sodium_crypto_sign_keypair();
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return [
        'pem' => base64_encode(sodium_crypto_sign_secretkey($keyPair)),
        'jwk' => ['kid' => $kid, 'kty' => 'OKP', 'crv' => 'Ed25519', 'x' => $encode(sodium_crypto_sign_publickey($keyPair))],
    ];
}

function canvaControllerApi(string $path): string
{
    return config('trypost.media_sources.canva.api').$path;
}

/**
 * @param  array<string, mixed>  $responses
 */
function canvaControllerFakeApi(array $jwk, array $responses = []): void
{
    Http::fake([
        canvaControllerApi('/connect/keys') => Http::response(['keys' => [$jwk]]),
        canvaControllerApi('/oauth/token') => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 14400,
        ]),
        canvaControllerApi('/users/me') => Http::response(['team_user' => ['user_id' => 'canva-user', 'team_id' => 'canva-team']]),
        canvaControllerApi('/designs') => Http::response([
            'design' => [
                'id' => 'DAF-new-design',
                'urls' => [
                    'edit_url' => 'https://www.canva.test/api/design/edit-token/edit',
                    'view_url' => 'https://www.canva.test/api/design/edit-token/view',
                ],
            ],
        ]),
        ...$responses,
    ]);
}

/**
 * @param  array<string, mixed>  $claims
 */
function canvaControllerJwt(string $pem, array $claims = [], string $kid = 'canva-kid-1'): string
{
    return JWT::encode([
        'aud' => 'canva-client-id',
        'type' => 'rti',
        'exp' => now()->addDay()->getTimestamp(),
        'jti' => (string) Str::uuid(),
        'design_id' => 'DAF-returned-design',
        'correlation_state' => 'known-correlation-key',
        'sub' => 'canva-user',
        'team_id' => 'canva-team',
        ...$claims,
    ], $pem, 'EdDSA', $kid);
}

function canvaControllerCorrelation(User $user, Workspace $workspace, MediaSourceConnection $connection, string $key = 'known-correlation-key'): void
{
    Cache::put("canva-design:{$key}", [
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'nonce' => CANVA_TEST_NONCE,
        'replaces_media_id' => null,
    ], 86400);
}

test('without a connection, a preset starts Canva OAuth with PKCE, state and the scopes', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);

    $response = $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'portrait_4_5', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $pending = session('canva_oauth');
    $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', data_get($pending, 'verifier'), true)), '+/', '-_'), '=');

    expect(Str::before($location, '?'))->toBe(config('trypost.media_sources.canva.authorize_url'))
        ->and($query)->toMatchArray([
            'response_type' => 'code',
            'client_id' => 'canva-client-id',
            'redirect_uri' => route('app.integrations.canva.callback'),
            'scope' => 'design:content:read design:content:write design:meta:read',
            'code_challenge_method' => 's256',
            'code_challenge' => $expectedChallenge,
            'state' => data_get($pending, 'state'),
        ])
        ->and(strlen((string) data_get($pending, 'verifier')))->toBeGreaterThanOrEqual(43)->toBeLessThanOrEqual(128)
        ->and(data_get($pending, 'preset'))->toBe('portrait_4_5')
        ->and(data_get($pending, 'nonce'))->toBe(CANVA_TEST_NONCE)
        ->and(data_get($pending, 'workspace_id'))->toBe($this->workspace->id);

    Http::assertNothingSent();
});

test('the code challenge method comes from config', function () {
    config()->set('trypost.media_sources.canva.code_challenge_method', 'S256');

    $location = $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]))
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(data_get($query, 'code_challenge_method'))->toBe('S256');
});

test('a missing or malformed nonce is rejected', function (?string $nonce) {
    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.designs.create', array_filter(['preset' => 'square', 'nonce' => $nonce])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('nonce');
})->with(['missing' => [null], 'too short' => ['short'], 'unsafe characters' => ['composer nonce/../0123456']]);

test('the shared prop carries the Canva presets from the enum, in order', function () {
    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mediaSources.menu.0.source', 'canva')
            ->where('mediaSources.menu.0.presets', CanvaPreset::options()));

    expect(CanvaPreset::options())->toBe([
        ['value' => 'square', 'width' => 1200, 'height' => 1200, 'is_default' => true],
        ['value' => 'portrait_4_5', 'width' => 1080, 'height' => 1350, 'is_default' => false],
        ['value' => 'portrait_3_4', 'width' => 1080, 'height' => 1440, 'is_default' => false],
        ['value' => 'landscape', 'width' => 1200, 'height' => 627, 'is_default' => false],
        ['value' => 'story', 'width' => 1080, 'height' => 1920, 'is_default' => false],
    ]);
});

test('an invalid preset is rejected', function () {
    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.designs.create', ['preset' => 'banner', 'nonce' => CANVA_TEST_NONCE]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('preset');
});

test('every Canva route is a 404 while Canva is not configured', function (array $config) {
    config()->set($config);

    $this->actingAs($this->user)->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]))->assertNotFound();
    $this->actingAs($this->user)->get(route('app.integrations.canva.callback', ['state' => 'x', 'code' => 'y']))->assertNotFound();
    $this->actingAs($this->user)->get(route('app.integrations.canva.return', ['correlation_jwt' => 'x']))->assertNotFound();
})->with([
    'flag off' => [['trypost.media_sources.canva.enabled' => false]],
    'no client id' => [['services.canva.client_id' => '']],
    'no client secret' => [['services.canva.client_secret' => null]],
]);

test('a user outside the workspace cannot open Canva', function () {
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]))
        ->assertForbidden();
});

test('a callback with a bad state renders a failed popup and connects nothing', function (?string $state, ?string $error) {
    canvaControllerFakeApi($this->signingKey['jwk']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', array_filter([
            'code' => 'auth-code',
            'state' => $state ?? session('canva_oauth.state'),
            'error' => $error,
        ])))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('source', 'canva')
            ->where('success', false)
            ->where('nonce', CANVA_TEST_NONCE)
            ->where('importId', null)
            ->where('message', __('posts.composer.media_sources.errors.canva_connect_failed')));

    expect(MediaSourceConnection::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token'));
})->with([
    'wrong state' => ['forged-state', null],
    'empty state' => ['', null],
]);

test('a callback without a pending sign-in renders a failed popup', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => 'anything']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect(MediaSourceConnection::query()->count())->toBe(0);
});

test('a good callback stores one connection and opens a design at the preset size', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'portrait_4_5', 'nonce' => CANVA_TEST_NONCE]));
    $pending = session('canva_oauth');

    $location = $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => data_get($pending, 'state')]))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $correlation = data_get($query, 'correlation_state');

    expect(Str::before($location, '?'))->toBe('https://www.canva.test/api/design/edit-token/edit')
        ->and(strlen($correlation))->toBeLessThanOrEqual(50)
        ->and($correlation)->toMatch('/^[A-Za-z0-9]+$/')
        ->and(Cache::get("canva-design:{$correlation}"))->toMatchArray([
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'nonce' => CANVA_TEST_NONCE,
            'replaces_media_id' => null,
        ])
        ->and(session('canva_oauth'))->toBeNull();

    $connection = MediaSourceConnection::query()->sole();

    expect($connection->user_id)->toBe($this->user->id)
        ->and($connection->source)->toBe(Source::Canva)
        ->and($connection->external_user_id)->toBe('canva-user')
        ->and($connection->external_team_id)->toBe('canva-team')
        ->and($connection->access_token)->toBe('fresh-access-token')
        ->and($connection->refresh_token)->toBe('rotated-refresh-token')
        ->and(Cache::get("canva-design:{$correlation}")['connection_id'])->toBe($connection->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/oauth/token')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('canva-client-id:canva-client-secret'))
        && $request['grant_type'] === 'authorization_code'
        && $request['code'] === 'auth-code'
        && $request['code_verifier'] === data_get($pending, 'verifier')
        && $request['redirect_uri'] === route('app.integrations.canva.callback'));

    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs')
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer fresh-access-token')
        && $request['design_type'] === ['type' => 'custom', 'width' => 1080, 'height' => 1350]);
});

test('a failed code exchange renders a failed popup', function () {
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/oauth/token') => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    $this->actingAs($this->user)->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => session('canva_oauth.state')]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect(MediaSourceConnection::query()->count())->toBe(0);
});

test('a callback whose Canva user lookup fails renders a failed popup and stores no connection', function () {
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/users/me') => Http::response(['code' => 'internal_error'], 500),
    ]);

    $this->actingAs($this->user)->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => session('canva_oauth.state')]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('success', false)
            ->where('nonce', CANVA_TEST_NONCE)
            ->where('message', __('posts.composer.media_sources.errors.canva_connect_failed')));

    expect(MediaSourceConnection::query()->count())->toBe(0);
    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/oauth/token'));
    Http::assertNotSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs'));
});

test('stored tokens are encrypted at rest and readable through the model', function () {
    $connection = MediaSourceConnection::factory()->for($this->user)->create([
        'access_token' => 'plain-access-token',
        'refresh_token' => 'plain-refresh-token',
    ]);

    $raw = DB::table('media_source_connections')->where('id', $connection->id)->first();

    expect($raw->access_token)->not->toBe('plain-access-token')->not->toContain('plain-access-token')
        ->and($raw->refresh_token)->not->toBe('plain-refresh-token')->not->toContain('plain-refresh-token')
        ->and($connection->fresh()->access_token)->toBe('plain-access-token')
        ->and($connection->fresh()->refresh_token)->toBe('plain-refresh-token')
        ->and($connection->toArray())->not->toHaveKeys(['access_token', 'refresh_token']);
});

test('a connected user goes straight to a new design; the connection is per user', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);
    MediaSourceConnection::factory()->for($this->user)->create(['access_token' => 'stored-token']);

    $location = $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'story', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirect()
        ->headers->get('Location');

    expect($location)->toStartWith('https://www.canva.test/api/design/edit-token/edit?correlation_state=');
    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs')
        && $request->hasHeader('Authorization', 'Bearer stored-token')
        && $request['design_type'] === ['type' => 'custom', 'width' => 1080, 'height' => 1920]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/oauth/token'));

    $teammate = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($teammate->id, membershipPivot('member'));
    $teammate->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($teammate->fresh())
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'story', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirect()
        ->assertRedirectContains(config('trypost.media_sources.canva.authorize_url'));
});

test('an edit_url outside the configured editor hosts is never followed', function (string $editUrl) {
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/designs') => Http::response(['design' => ['id' => 'DAF-new-design', 'urls' => ['edit_url' => $editUrl]]]),
    ]);
    MediaSourceConnection::factory()->for($this->user)->create(['access_token' => 'stored-token']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'story', 'nonce' => CANVA_TEST_NONCE]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('success', false)
            ->where('message', __('posts.composer.media_sources.errors.canva_connect_failed')));

    expect(Cache::get('canva-design:known-correlation-key'))->toBeNull();
})->with([
    'another host' => 'https://evil.example/api/design/edit-token/edit',
    'a lookalike host' => 'https://www.canva.test.evil.example/edit',
    'plain http' => 'http://www.canva.test/api/design/edit-token/edit',
    'a backslash' => 'https://www.canva.test\\@evil.example/edit',
    'a backslash in the path' => 'https://www.canva.test/api\\design/edit',
    'userinfo' => 'https://user@www.canva.test/api/design/edit-token/edit',
    'userinfo with password' => 'https://user:pass@www.canva.test/api/design/edit-token/edit',
    'no url' => '',
]);

test('an expired access token is refreshed and the rotated refresh token is saved', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);
    $connection = MediaSourceConnection::factory()->for($this->user)->expired()->create(['refresh_token' => 'old-refresh-token']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirectContains('https://www.canva.test/api/design/edit-token/edit');

    $connection->refresh();

    expect($connection->access_token)->toBe('fresh-access-token')
        ->and($connection->refresh_token)->toBe('rotated-refresh-token')
        ->and($connection->expires_at->isFuture())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/oauth/token')
        && $request['grant_type'] === 'refresh_token'
        && $request['refresh_token'] === 'old-refresh-token');
    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs')
        && $request->hasHeader('Authorization', 'Bearer fresh-access-token'));
});

test('a revoked refresh token deletes the connection and restarts OAuth in the popup', function () {
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/oauth/token') => Http::response(['error' => 'invalid_grant', 'error_description' => 'revoked'], 400),
    ]);
    MediaSourceConnection::factory()->for($this->user)->expired()->create();

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'landscape', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirectContains(config('trypost.media_sources.canva.authorize_url'));

    expect(MediaSourceConnection::query()->count())->toBe(0)
        ->and(session('canva_oauth.preset'))->toBe('landscape');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs'));
});

test('a refused refresh does not delete a connection another worker already rotated', function () {
    $connection = MediaSourceConnection::factory()->for($this->user)->expired()->create(['refresh_token' => 'spent-refresh-token']);
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/oauth/token') => function () use ($connection) {
            MediaSourceConnection::query()->whereKey($connection->id)->first()->update([
                'access_token' => 'other-worker-access-token',
                'refresh_token' => 'other-worker-refresh-token',
                'expires_at' => now()->addHours(4),
            ]);

            return Http::response(['error' => 'invalid_grant'], 400);
        },
    ]);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.create', ['preset' => 'square', 'nonce' => CANVA_TEST_NONCE]))
        ->assertRedirectContains('https://www.canva.test/api/design/edit-token/edit');

    expect($connection->fresh()->refresh_token)->toBe('other-worker-refresh-token');
    Http::assertSent(fn (Request $request): bool => $request->url() === canvaControllerApi('/designs')
        && $request->hasHeader('Authorization', 'Bearer other-worker-access-token'));
});

test('a return with a valid JWT queues the export of the returned design', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    canvaControllerCorrelation($this->user, $this->workspace, $connection);

    $importId = null;

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => canvaControllerJwt($this->signingKey['pem'])]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$importId) {
            $page->component('integrations/MediaSourcePopup')
                ->where('source', 'canva')
                ->where('success', true)
                ->where('replaces', null)
                ->where('importId', fn (?string $id): bool => Str::isUuid((string) $id));
            $importId = data_get($page->toArray(), 'props.importId');
        });

    Queue::assertPushed(ExportCanvaDesign::class, fn (ExportCanvaDesign $job): bool => $job->importId === $importId
        && $job->designId === 'DAF-returned-design'
        && $job->connectionId === $connection->id
        && $job->workspaceId === $this->workspace->id
        && $job->userId === $this->user->id);

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'pending', 'replaces' => null]);

    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.returns.show', CANVA_TEST_NONCE))
        ->assertOk()
        ->assertExactJson(['import_id' => $importId, 'replaces' => null]);

    expect(Cache::has("canva-return:{$this->user->id}:".CANVA_TEST_NONCE))->toBeTrue()
        ->and(Cache::has('canva-return:'.CANVA_TEST_NONCE))->toBeFalse();
});

test('the return fallback answers only its own user and workspace, once the return happened', function () {
    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.returns.show', CANVA_TEST_NONCE))
        ->assertNotFound();

    Cache::put("canva-return:{$this->user->id}:".CANVA_TEST_NONCE, [
        'user_id' => $this->user->id,
        'workspace_id' => $this->workspace->id,
        'import_id' => 'import-1',
        'replaces' => null,
    ], 3600);

    $teammate = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($teammate->id, membershipPivot('member'));
    $teammate->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($teammate->fresh())
        ->getJson(route('app.integrations.canva.returns.show', CANVA_TEST_NONCE))
        ->assertNotFound();

    Cache::put("canva-return:{$teammate->id}:".CANVA_TEST_NONCE, [
        'user_id' => $teammate->id,
        'workspace_id' => $this->workspace->id,
        'import_id' => 'teammate-import',
        'replaces' => null,
    ], 3600);

    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.returns.show', CANVA_TEST_NONCE))
        ->assertOk()
        ->assertJsonPath('import_id', 'import-1');

    $this->actingAs($teammate->fresh())
        ->getJson(route('app.integrations.canva.returns.show', CANVA_TEST_NONCE))
        ->assertOk()
        ->assertJsonPath('import_id', 'teammate-import');
});

test('a return that cannot be trusted renders a failed popup and queues nothing', function (string $case) {
    canvaControllerFakeApi($this->signingKey['jwk']);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    canvaControllerCorrelation($this->user, $this->workspace, $connection);

    $otherUser = User::factory()->create(['account_id' => $this->account->id]);
    $otherConnection = MediaSourceConnection::factory()->for($otherUser)->create();
    canvaControllerCorrelation($otherUser, $this->workspace, $otherConnection, 'someone-elses-key');

    $jwt = match ($case) {
        'wrong aud' => canvaControllerJwt($this->signingKey['pem'], ['aud' => 'another-client']),
        'wrong type' => canvaControllerJwt($this->signingKey['pem'], ['type' => 'access']),
        'expired' => canvaControllerJwt($this->signingKey['pem'], ['exp' => now()->subMinute()->getTimestamp()]),
        'unknown correlation' => canvaControllerJwt($this->signingKey['pem'], ['correlation_state' => 'never-issued']),
        'correlation of another user' => canvaControllerJwt($this->signingKey['pem'], ['correlation_state' => 'someone-elses-key']),
        'bad signature' => canvaControllerJwt(canvaControllerSigningKey()['pem']),
        'another canva user' => canvaControllerJwt($this->signingKey['pem'], ['sub' => 'someone-else']),
        'another canva team' => canvaControllerJwt($this->signingKey['pem'], ['team_id' => 'another-team']),
        'not a jwt' => 'not-a-jwt',
    };

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('success', false)
            ->where('importId', null)
            ->where('message', __('posts.composer.media_sources.errors.canva_export_failed')));

    Queue::assertNotPushed(ExportCanvaDesign::class);
})->with(['wrong aud', 'wrong type', 'expired', 'unknown correlation', 'correlation of another user', 'bad signature', 'another canva user', 'another canva team', 'not a jwt']);

test('a return JWT is single-use', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    canvaControllerCorrelation($this->user, $this->workspace, $connection);
    $jwt = canvaControllerJwt($this->signingKey['pem']);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    Queue::assertPushed(ExportCanvaDesign::class, 1);
});

test('a return in another workspace than the design was opened in is refused', function () {
    canvaControllerFakeApi($this->signingKey['jwk']);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    canvaControllerCorrelation($this->user, $this->workspace, $connection);

    $otherWorkspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);
    $otherWorkspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $jwt = canvaControllerJwt($this->signingKey['pem']);

    $this->actingAs($this->user->fresh())
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    Queue::assertNotPushed(ExportCanvaDesign::class);

    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    Queue::assertPushed(ExportCanvaDesign::class, 1);
});

test('a JWT signed with a rotated key refetches the keys once', function () {
    $rotated = canvaControllerSigningKey('canva-kid-2');
    $keyFetches = 0;
    canvaControllerFakeApi($this->signingKey['jwk'], [
        canvaControllerApi('/connect/keys') => function () use (&$keyFetches, $rotated) {
            $keyFetches++;

            return Http::response(['keys' => $keyFetches === 1 ? [$this->signingKey['jwk']] : [$rotated['jwk']]]);
        },
    ]);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    canvaControllerCorrelation($this->user, $this->workspace, $connection);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => canvaControllerJwt($this->signingKey['pem'])]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => canvaControllerJwt($rotated['pem'], kid: 'canva-kid-2')]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', true));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => canvaControllerJwt(canvaControllerSigningKey('canva-kid-3')['pem'], kid: 'canva-kid-3')]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('success', false));

    expect($keyFetches)->toBe(2);
});

test('telescope masks the Canva OAuth secrets in recorded client requests', function () {
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

        $request = new Request(new GuzzleRequest('POST', canvaControllerApi('/oauth/token'), ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'authorization_code',
            'code' => 'secret-code',
            'code_verifier' => 'secret-verifier',
            'refresh_token' => 'secret-refresh',
            'client_secret' => 'secret-client',
        ])));
        $response = new ClientResponse(new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'access_token' => 'secret-access',
            'refresh_token' => 'secret-rotated',
            'expires_in' => 14400,
        ])));

        expect($watcher->recordedPayload($request))->toBe([
            'grant_type' => 'authorization_code',
            'code' => '********',
            'code_verifier' => '********',
            'refresh_token' => '********',
            'client_secret' => '********',
        ])->and($watcher->recordedResponse($response))->toBe([
            'access_token' => '********',
            'refresh_token' => '********',
            'expires_in' => 14400,
        ]);
    } finally {
        Telescope::$hiddenRequestParameters = $hiddenParameters;
        Telescope::$hiddenResponseParameters = $hiddenResponseParameters;
        Telescope::$hiddenRequestHeaders = $hiddenHeaders;
        Telescope::$filterUsing = $filters;
    }
});

test('the connection is deleted with its user and there is no disconnect route', function () {
    $member = User::factory()->create(['account_id' => $this->account->id]);
    $connection = MediaSourceConnection::factory()->for($member)->create();
    $other = MediaSourceConnection::factory()->for($this->user)->create();

    $member->delete();

    expect(MediaSourceConnection::query()->find($connection->id))->toBeNull()
        ->and(MediaSourceConnection::query()->find($other->id))->not->toBeNull()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()
            ->filter(fn (string $name): bool => str_contains($name, 'canva'))
            ->sort()->values()->all())
        ->toBe([
            'app.integrations.canva.callback',
            'app.integrations.canva.designs.create',
            'app.integrations.canva.designs.edit',
            'app.integrations.canva.return',
            'app.integrations.canva.returns.show',
        ]);
});
