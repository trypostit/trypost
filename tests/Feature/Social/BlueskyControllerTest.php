<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
});

test('bluesky connect page can be rendered', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.bluesky.connect'));

    $response->assertOk();
});

test('user can connect bluesky account with valid credentials', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'emailConfirmed' => true,
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'accessJwt' => 'test-access-token',
            'refreshJwt' => 'test-refresh-token',
        ], 200),
        'https://bsky.social/xrpc/com.atproto.server.getSession' => Http::response(['did' => 'did:plc:testuser123', 'emailConfirmed' => true]),
        'https://bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'displayName' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'xxxx-xxxx-xxxx-xxxx',
    ]);

    $response->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    finishSocialConnect(Platform::Bluesky)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky->value,
        'platform_user_id' => 'did:plc:testuser123',
        'username' => 'testuser.bsky.social',
        'status' => Status::Connected->value,
    ]);
});

test('user cannot connect bluesky with invalid credentials', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'error' => 'AuthenticationRequired',
            'message' => 'Invalid identifier or password',
        ], 401),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('password');

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky->value,
    ]);
});

test('bluesky refuses login without a confirmed email and discards pending credentials', function (array $verification, string $reason) {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'accessJwt' => 'secret-access',
            'refreshJwt' => 'secret-refresh',
            ...$verification,
        ]),
    ]);

    $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'xxxx-xxxx-xxxx-xxxx',
    ])->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    expect(PendingConnection::current()->failure())->toBe($reason)
        ->and(PendingConnection::current()->identities())->toBe([])
        ->and(session('social_connect.identities'))->toBeNull()
        ->and($this->workspace->socialAccounts()->count())->toBe(0);

    $this->get(route('app.social.connect.show', Platform::Bluesky))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('accounts/ConnectFinish')
            ->where('state', 'error')
            ->where('reason', __("accounts.connect.errors.{$reason}"))
            ->has('identities', 0));

    $this->post(route('app.social.connect.finish', Platform::Bluesky), [
        'identities' => ['bluesky:did:plc:testuser123'],
    ])->assertRedirect();

    expect($this->workspace->socialAccounts()->count())->toBe(0);
    Http::assertSentCount(1);
})->with([
    'unconfirmed' => [['emailConfirmed' => false], 'bluesky_email_unconfirmed'],
    'missing' => [[], 'error_connecting'],
    'null' => [['emailConfirmed' => null], 'error_connecting'],
    'malformed' => [['emailConfirmed' => 'true'], 'error_connecting'],
]);

test('bluesky rechecks email at finish and keeps an existing account unchanged on refusal', function (bool $reconnecting) {
    $account = $reconnecting ? SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'did:plc:testuser123',
        'access_token' => 'original-token',
    ]) : null;

    startSocialConnect($this->workspace, Platform::Bluesky, $account);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'accessJwt' => 'fresh-access',
            'refreshJwt' => 'fresh-refresh',
            'emailConfirmed' => true,
        ]),
        'https://bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response(['displayName' => 'Test User']),
        'https://bsky.social/xrpc/com.atproto.server.getSession' => Http::response([
            'did' => 'did:plc:testuser123',
            'emailConfirmed' => false,
        ]),
    ]);

    $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'xxxx-xxxx-xxxx-xxxx',
    ])->assertRedirect();

    expect(PendingConnection::current()->isReady())->toBeTrue();

    finishSocialConnect(Platform::Bluesky)->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    expect(PendingConnection::current()->failure())->toBe('bluesky_email_unconfirmed')
        ->and(session('social_connect.identities'))->toBeNull()
        ->and($this->workspace->socialAccounts()->count())->toBe($reconnecting ? 1 : 0);

    if ($account !== null) {
        expect($account->fresh()->access_token)->toBe('original-token');
    }

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/com.atproto.server.getSession')
        && $request->hasHeader('Authorization', 'Bearer fresh-access'));
})->with(['new connection' => false, 'reconnect' => true]);

test('bluesky refuses an older pending connection when session verification cannot confirm the account', function (array $body, int $status) {
    startSocialConnect($this->workspace, Platform::Bluesky)->offer([
        PendingConnection::identity(Platform::Bluesky, 'did:plc:testuser123', 'Test User', 'testuser.bsky.social', null, 'profile', [
            'access_token' => 'pending-access',
            'refresh_token' => 'pending-refresh',
        ]),
    ]);

    Http::fake(['https://bsky.social/xrpc/com.atproto.server.getSession' => Http::response($body, $status)]);

    $this->actingAs($this->user);
    finishSocialConnect(Platform::Bluesky)->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    expect(PendingConnection::current()->isReady())->toBeFalse()
        ->and(session('social_connect.identities'))->toBeNull()
        ->and($this->workspace->socialAccounts()->count())->toBe(0);
})->with([
    'unconfirmed email' => [['did' => 'did:plc:testuser123', 'emailConfirmed' => false], 200],
    'missing confirmation' => [['did' => 'did:plc:testuser123'], 200],
    'different identity' => [['did' => 'did:plc:other', 'emailConfirmed' => true], 200],
    'expired token' => [['error' => 'ExpiredToken'], 401],
    'provider unavailable' => [[], 503],
]);

test('the bluesky email confirmation message exists in every locale', function () {
    foreach (Locale::cases() as $locale) {
        $translations = require lang_path("{$locale->value}/accounts.php");

        expect(data_get($translations, 'connect.errors.bluesky_email_unconfirmed'))->toBeString()->not->toBeEmpty();
    }
});

test('user can connect multiple bluesky accounts', function () {

    SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'did:plc:existing123',
    ]);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'emailConfirmed' => true,
            'did' => 'did:plc:newuser456',
            'handle' => 'newuser.bsky.social',
            'accessJwt' => 'test-access-token',
            'refreshJwt' => 'test-refresh-token',
        ], 200),
        'https://bsky.social/xrpc/com.atproto.server.getSession' => Http::response(['did' => 'did:plc:newuser456', 'emailConfirmed' => true]),
        'https://bsky.social/xrpc/app.bsky.actor.getProfile*' => Http::response([
            'did' => 'did:plc:newuser456',
            'handle' => 'newuser.bsky.social',
            'displayName' => 'New User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'newuser.bsky.social',
        'password' => 'xxxx-xxxx-xxxx-xxxx',
    ]);

    $response->assertRedirect(route('app.social.connect.show', Platform::Bluesky));
    finishSocialConnect(Platform::Bluesky)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Bluesky)->count())->toBe(2);
});

test('bluesky connection validates required fields', function () {
    $response = $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => '',
        'password' => '',
    ]);

    $response->assertSessionHasErrors(['identifier', 'password']);
});

test('bluesky store reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
        'platform_user_id' => 'did:plc:testuser123',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace, Platform::Bluesky, $account->id);

    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'emailConfirmed' => true,
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'accessJwt' => 'fresh-access-token',
            'refreshJwt' => 'fresh-refresh-token',
        ], 200),
        "{$service}/xrpc/com.atproto.server.getSession" => Http::response(['did' => 'did:plc:testuser123', 'emailConfirmed' => true]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response([
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'displayName' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.bluesky.store'), [
            'identifier' => 'testuser.bsky.social',
            'password' => 'xxxx-xxxx-xxxx-xxxx',
        ])
        ->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    finishSocialConnect(Platform::Bluesky)->assertRedirect();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->username)->toBe('testuser.bsky.social')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('bluesky reconnect that authenticates another handle says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
        'platform_user_id' => 'did:plc:testuser123',
        'username' => 'old',
    ]);

    startSocialConnect($this->workspace, Platform::Bluesky, $account->id);

    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'emailConfirmed' => true,
            'did' => 'did:plc:someoneelse999',
            'handle' => 'someone-else.bsky.social',
            'accessJwt' => 'other-access-token',
            'refreshJwt' => 'other-refresh-token',
        ], 200),
        "{$service}/xrpc/com.atproto.server.getSession" => Http::response(['did' => 'did:plc:someoneelse999', 'emailConfirmed' => true]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response([
            'did' => 'did:plc:someoneelse999',
            'handle' => 'someone-else.bsky.social',
            'displayName' => 'Someone Else',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.social.bluesky.store'), [
            'identifier' => 'someone-else.bsky.social',
            'password' => 'xxxx-xxxx-xxxx-xxxx',
        ])
        ->assertRedirect(route('app.social.connect.show', Platform::Bluesky));

    expect(socialConnectFailure())->toBe('wrong_account');

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('did:plc:testuser123');
});

test('bluesky connect errors are shown in the user language', function () {
    $this->user->update(['locale' => Locale::PortugueseBrazil]);
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
            'error' => 'AuthenticationRequired',
            'message' => 'Invalid identifier or password',
        ], 401),
    ]);

    $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['password' => 'Credenciais inválidas.']);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.createSession' => fn () => throw new RuntimeException('boom'),
    ]);

    $this->actingAs($this->user)->post(route('app.social.bluesky.store'), [
        'identifier' => 'testuser.bsky.social',
        'password' => 'wrong-password',
    ])->assertSessionHasErrors(['password' => 'Erro ao conectar ao Bluesky. Tente novamente.']);
});
