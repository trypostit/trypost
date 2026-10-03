<?php

declare(strict_types=1);

use App\Jobs\Media\ExportCanvaDesign;
use App\Models\Account;
use App\Models\Media;
use App\Models\MediaSourceConnection;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

const EDIT_IN_CANVA_NONCE = 'edit-canva-nonce-0123456789';

const EDIT_IN_CANVA_DESIGN = 'DAF-canva-design';

beforeEach(function () {
    Queue::fake();
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
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.canva.api' => 'https://api.canva.test/rest/v1',
        'trypost.media_sources.canva.authorize_url' => 'https://www.canva.test/api/oauth/authorize',
        'trypost.media_sources.canva.editor_hosts' => '*.canva.test',
    ]);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $this->signingPem = $pem;
    $this->signingJwk = ['kty' => 'RSA', 'kid' => 'edit-kid', 'alg' => 'RS256', 'use' => 'sig', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])];
});

function editInCanvaApi(string $path): string
{
    return config('trypost.media_sources.canva.api').$path;
}

/**
 * @param  array<string, mixed>  $responses
 */
function editInCanvaFakeApi(array $jwk, array $responses = []): void
{
    Http::fake([
        editInCanvaApi('/connect/keys') => Http::response(['keys' => [$jwk]]),
        editInCanvaApi('/oauth/token') => Http::response([
            'access_token' => 'fresh-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'token_type' => 'Bearer',
            'expires_in' => 14400,
        ]),
        editInCanvaApi('/users/me') => Http::response(['team_user' => ['user_id' => 'canva-user', 'team_id' => 'canva-team']]),
        editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN) => Http::response([
            'design' => [
                'id' => EDIT_IN_CANVA_DESIGN,
                'urls' => [
                    'edit_url' => 'https://www.canva.test/api/design/existing-token/edit',
                    'view_url' => 'https://www.canva.test/api/design/existing-token/view',
                ],
            ],
        ]),
        ...$responses,
    ]);
}

function editInCanvaMedia(Workspace $workspace, ?Post $post = null, ?string $designId = EDIT_IN_CANVA_DESIGN): Media
{
    $factory = $post === null ? Media::factory()->temporaryUpload($workspace) : Media::factory()->ownedByPost($post);

    return $factory->stored()->create([
        'mime_type' => 'image/png',
        'meta' => array_filter([
            'width' => 1080,
            'height' => 1080,
            'source' => $designId === null ? null : 'canva',
            'source_meta' => $designId === null ? null : ['design_id' => $designId],
        ]),
    ]);
}

/**
 * @return array{location: string, correlation: string}
 */
function editInCanvaRedirect(mixed $response): array
{
    $location = (string) $response->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return ['location' => $location, 'correlation' => (string) data_get($query, 'correlation_state')];
}

test('editing a Canva media fetches a fresh edit url and remembers which media the return replaces', function (bool $owned) {
    editInCanvaFakeApi($this->signingJwk);
    MediaSourceConnection::factory()->for($this->user)->create(['access_token' => 'stored-token']);
    $post = $owned ? Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]) : null;
    $media = editInCanvaMedia($this->workspace, $post);

    ['location' => $location, 'correlation' => $correlation] = editInCanvaRedirect($this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE])));

    expect(Str::before($location, '?'))->toBe('https://www.canva.test/api/design/existing-token/edit')
        ->and(strlen($correlation))->toBeLessThanOrEqual(50)
        ->and($correlation)->toMatch('/^[A-Za-z0-9]+$/')
        ->and(Cache::get("canva-design:{$correlation}"))->toMatchArray([
            'user_id' => $this->user->id,
            'workspace_id' => $this->workspace->id,
            'nonce' => EDIT_IN_CANVA_NONCE,
            'replaces_media_id' => $media->id,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN)
        && $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer stored-token'));
    Http::assertNotSent(fn (Request $request): bool => $request->url() === editInCanvaApi('/designs') && $request->method() === 'POST');
})->with(['owned by a post' => [true], 'temporary upload' => [false]]);

test('a teammate can reopen a Canva media another member brought into the workspace', function () {
    editInCanvaFakeApi($this->signingJwk);
    $teammate = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($teammate->id, membershipPivot('member'));
    $teammate->update(['current_workspace_id' => $this->workspace->id]);
    MediaSourceConnection::factory()->for($teammate)->create(['access_token' => 'teammate-token']);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $media = editInCanvaMedia($this->workspace, $post);

    ['correlation' => $correlation] = editInCanvaRedirect($this->actingAs($teammate->fresh())
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE])));

    expect(Cache::get("canva-design:{$correlation}"))->toMatchArray([
        'user_id' => $teammate->id,
        'replaces_media_id' => $media->id,
    ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN)
        && $request->hasHeader('Authorization', 'Bearer teammate-token'));
});

test('a refused edit renders the edit error in the popup instead of redirecting', function (string $case) {
    editInCanvaFakeApi($this->signingJwk);
    MediaSourceConnection::factory()->for($this->user)->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $this->account->id, 'user_id' => $this->user->id]);

    $media = match ($case) {
        'another workspace' => editInCanvaMedia($otherWorkspace),
        'no design id' => editInCanvaMedia($this->workspace, designId: null),
        'empty design id' => editInCanvaMedia($this->workspace, designId: ''),
        'unknown media' => null,
    };

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media?->id ?? (string) Str::uuid(), 'nonce' => EDIT_IN_CANVA_NONCE]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('source', 'canva')
            ->where('success', false)
            ->where('nonce', EDIT_IN_CANVA_NONCE)
            ->where('importId', null)
            ->where('message', __('posts.composer.media_sources.errors.canva_edit_failed')));

    Http::assertNothingSent();
})->with(['another workspace', 'no design id', 'empty design id', 'unknown media']);

test('editing needs a nonce, createPost and Canva enabled', function () {
    editInCanvaFakeApi($this->signingJwk);
    $media = editInCanvaMedia($this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => 'bad nonce']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('nonce', null)
            ->where('message', __('posts.composer.media_sources.errors.canva_edit_failed')));

    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE]))
        ->assertForbidden();

    config()->set('trypost.media_sources.canva.enabled', false);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE]))
        ->assertNotFound();
});

test('a design this Canva account cannot open renders the edit error in the popup', function (int $status, string $code) {
    editInCanvaFakeApi($this->signingJwk, [
        editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN) => Http::response(['code' => $code, 'message' => 'nope'], $status),
    ]);
    MediaSourceConnection::factory()->for($this->user)->create();
    $media = editInCanvaMedia($this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('integrations/MediaSourcePopup')
            ->where('success', false)
            ->where('nonce', EDIT_IN_CANVA_NONCE)
            ->where('importId', null)
            ->where('message', __('posts.composer.media_sources.errors.canva_edit_failed')));
})->with([
    'forbidden' => [403, 'permission_denied'],
    'not found' => [404, 'design_not_found'],
]);

test('without a connection, editing signs in first and then reopens the same design', function () {
    editInCanvaFakeApi($this->signingJwk);
    $media = editInCanvaMedia($this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE]))
        ->assertRedirectContains(config('trypost.media_sources.canva.authorize_url'));

    $pending = session('canva_oauth');

    expect($pending)->toMatchArray([
        'preset' => null,
        'media_id' => $media->id,
        'design_id' => EDIT_IN_CANVA_DESIGN,
        'nonce' => EDIT_IN_CANVA_NONCE,
    ]);

    ['location' => $location, 'correlation' => $correlation] = editInCanvaRedirect($this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => data_get($pending, 'state')])));

    expect(Str::before($location, '?'))->toBe('https://www.canva.test/api/design/existing-token/edit')
        ->and(data_get(Cache::get("canva-design:{$correlation}"), 'replaces_media_id'))->toBe($media->id)
        ->and(MediaSourceConnection::query()->count())->toBe(1);

    Http::assertSent(fn (Request $request): bool => $request->url() === editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN)
        && $request->hasHeader('Authorization', 'Bearer fresh-access-token'));
    Http::assertNotSent(fn (Request $request): bool => $request->url() === editInCanvaApi('/designs') && $request->method() === 'POST');
});

test('a callback whose design cannot be opened renders the edit error', function () {
    editInCanvaFakeApi($this->signingJwk, [
        editInCanvaApi('/designs/'.EDIT_IN_CANVA_DESIGN) => Http::response(['code' => 'permission_denied'], 403),
    ]);
    $media = editInCanvaMedia($this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE]));

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.callback', ['code' => 'auth-code', 'state' => session('canva_oauth.state')]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('nonce', EDIT_IN_CANVA_NONCE)
            ->where('message', __('posts.composer.media_sources.errors.canva_edit_failed')));
});

/**
 * Opens the edit for `$media` and returns a signed return JWT for it.
 */
function editInCanvaOpenAndReturn(object $test, Media $media): string
{
    ['correlation' => $correlation] = editInCanvaRedirect($test->actingAs($test->user)
        ->get(route('app.integrations.canva.designs.edit', ['media' => $media->id, 'nonce' => EDIT_IN_CANVA_NONCE])));

    return JWT::encode([
        'aud' => 'canva-client-id',
        'type' => 'rti',
        'exp' => now()->addDay()->getTimestamp(),
        'jti' => (string) Str::uuid(),
        'design_id' => EDIT_IN_CANVA_DESIGN,
        'correlation_state' => $correlation,
        'sub' => 'canva-user',
        'team_id' => 'canva-team',
    ], $test->signingPem, 'RS256', 'edit-kid');
}

test('the return of an edit carries the replaced media to the popup, the status and the fallback', function () {
    editInCanvaFakeApi($this->signingJwk);
    $connection = MediaSourceConnection::factory()->for($this->user)->create();
    $media = editInCanvaMedia($this->workspace);
    $jwt = editInCanvaOpenAndReturn($this, $media);

    $importId = null;

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$importId, $media) {
            $page->component('integrations/MediaSourcePopup')
                ->where('success', true)
                ->where('nonce', EDIT_IN_CANVA_NONCE)
                ->where('replaces', $media->id)
                ->where('importId', fn (?string $id): bool => Str::isUuid((string) $id));
            $importId = data_get($page->toArray(), 'props.importId');
        });

    Queue::assertPushed(ExportCanvaDesign::class, fn (ExportCanvaDesign $job): bool => $job->importId === $importId
        && $job->designId === EDIT_IN_CANVA_DESIGN
        && $job->connectionId === $connection->id);

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'pending', 'replaces' => $media->id]);

    $done = Media::factory()->temporaryUpload($this->workspace)->create();
    MediaImportStatus::complete($importId, $done);

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => 'done', 'media_id' => $done->id, 'replaces' => $media->id]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('replaces', $media->id);

    $this->actingAs($this->user)
        ->getJson(route('app.integrations.canva.returns.show', EDIT_IN_CANVA_NONCE))
        ->assertOk()
        ->assertExactJson(['import_id' => $importId, 'replaces' => $media->id]);
});

test('a return whose replaced media left the workspace is refused and queues nothing', function () {
    editInCanvaFakeApi($this->signingJwk);
    MediaSourceConnection::factory()->for($this->user)->create();
    $media = editInCanvaMedia($this->workspace);
    $jwt = editInCanvaOpenAndReturn($this, $media);

    $media->delete();

    $this->actingAs($this->user)
        ->get(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('success', false)
            ->where('importId', null)
            ->where('nonce', EDIT_IN_CANVA_NONCE));

    Queue::assertNotPushed(ExportCanvaDesign::class);
});
