<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));

    fakePublicDns();
});

test('mastodon connect page can be rendered', function () {
    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.connect'));

    $response->assertOk();
});

test('user can initiate mastodon oauth flow', function () {
    Http::fake([
        'https://mastodon.social/api/v1/apps' => Http::response([
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'id' => '12345',
            'name' => config('app.name'),
            'redirect_uri' => route('app.social.mastodon.callback'),
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), [
            'instance' => 'https://mastodon.social',
        ]);

    expect($response->headers->get('Location'))
        ->toStartWith('https://mastodon.social/oauth/authorize');

    expect(session('mastodon_instance'))->toBe('https://mastodon.social');
    expect(session('mastodon_client_id'))->toBe('test-client-id');
    expect(session('mastodon_client_secret'))->toBe('test-client-secret');
    Http::assertSent(fn (ClientRequest $request): bool => $request['scopes'] === 'read:accounts read:statuses write:statuses write:media');
});

test('user cannot connect to invalid mastodon instance', function () {
    Http::fake([
        'https://invalid-instance.com/api/v1/apps' => Http::response([], 404),
    ]);

    $response = $this->actingAs($this->user)->post(route('app.social.mastodon.authorize'), [
        'instance' => 'https://invalid-instance.com',
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('instance');
});

test('mastodon oauth callback creates account', function () {
    // Setup session as if OAuth flow was initiated
    startSocialConnect($this->workspace->id, Platform::Mastodon);
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'test-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts read:statuses write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'acct' => 'testuser',
            'display_name' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    finishSocialConnect(Platform::Mastodon)->assertRedirect();

    $this->assertDatabaseHas('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon->value,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'status' => Status::Connected->value,
    ]);

    $account = SocialAccount::where('platform', Platform::Mastodon->value)->first();
    expect($account->scopes)->toBe(['read:accounts', 'read:statuses', 'write:statuses', 'write:media']);
});

test('mastodon callback fails with invalid state', function () {
    startSocialConnect($this->workspace->id, Platform::Mastodon);
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'correct-state',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'wrong-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    $this->assertDatabaseMissing('social_accounts', [
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon->value,
    ]);
});

test('mastodon callback fails with expired session', function () {
    // No session data - simulating expired session

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    expect(socialConnectFailure())->toBeNull();
});

test('user can connect multiple mastodon accounts', function () {
    SocialAccount::factory()->mastodon()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
    ]);

    startSocialConnect($this->workspace->id, Platform::Mastodon);
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'new-access-token',
            'token_type' => 'Bearer',
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '987654321',
            'username' => 'anotheruser',
            'acct' => 'anotheruser',
            'display_name' => 'Another User',
        ], 200),
    ]);

    $response = $this->actingAs($this->user)->get(route('app.social.mastodon.callback', [
        'code' => 'test-auth-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('app.social.connect.show', Platform::Mastodon));
    finishSocialConnect(Platform::Mastodon)->assertRedirect();

    expect($this->workspace->socialAccounts()->where('platform', Platform::Mastodon)->count())->toBe(2);
});

test('mastodon connection validates instance url', function () {
    $response = $this->actingAs($this->user)->post(route('app.social.mastodon.authorize'), [
        'instance' => 'not-a-valid-url',
    ]);

    $response->assertSessionHasErrors('instance');
});

test('mastodon works with custom instances', function () {
    Http::fake([
        'https://techhub.social/api/v1/apps' => Http::response([
            'client_id' => 'custom-client-id',
            'client_secret' => 'custom-client-secret',
            'id' => '67890',
            'name' => config('app.name'),
        ], 200),
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), [
            'instance' => 'https://techhub.social',
        ]);

    expect($response->headers->get('Location'))
        ->toStartWith('https://techhub.social/oauth/authorize');

    expect(session('mastodon_instance'))->toBe('https://techhub.social');
});

test('mastodon callback reconnects the original card', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => '123456789',
        'username' => 'old',
        'access_token' => 'expired-token',
        'status' => Status::TokenExpired,
    ]);

    startSocialConnect($this->workspace->id, Platform::Mastodon, $account->id);
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'fresh-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts read:statuses write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '123456789',
            'username' => 'testuser',
            'acct' => 'testuser',
            'display_name' => 'Test User',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.mastodon.callback', ['code' => 'test-auth-code', 'state' => 'test-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    finishSocialConnect(Platform::Mastodon)->assertRedirect();

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->access_token)->toBe('fresh-access-token')
        ->and($account->fresh()->username)->toBe('testuser')
        ->and($account->fresh()->status)->toBe(Status::Connected);
});

test('mastodon reconnect that authorizes another account says so instead of connecting', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => '123456789',
        'username' => 'old',
    ]);

    startSocialConnect($this->workspace->id, Platform::Mastodon, $account->id);
    session([
        'mastodon_instance' => 'https://mastodon.social',
        'mastodon_client_id' => 'test-client-id',
        'mastodon_client_secret' => 'test-client-secret',
        'mastodon_oauth_state' => 'test-state',
    ]);

    Http::fake([
        'https://mastodon.social/oauth/token' => Http::response([
            'access_token' => 'other-access-token',
            'token_type' => 'Bearer',
            'scope' => 'read:accounts read:statuses write:statuses write:media',
            'created_at' => time(),
        ], 200),
        'https://mastodon.social/api/v1/accounts/verify_credentials' => Http::response([
            'id' => '999999999',
            'username' => 'someone-else',
            'acct' => 'someone-else',
            'display_name' => 'Someone Else',
            'avatar' => null,
        ], 200),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.social.mastodon.callback', ['code' => 'test-auth-code', 'state' => 'test-state']))
        ->assertRedirect(route('app.social.connect.show', Platform::Mastodon));

    expect(socialConnectFailure())->toBe('wrong_account');

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($account->fresh()->platform_user_id)->toBe('123456789');
});

test('mastodon refuses an instance on a private network without calling it', function (string $instance) {
    Http::fake(['*' => Http::response(['client_id' => 'leaked-id', 'client_secret' => 'leaked-secret'])]);

    $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => $instance])
        ->assertRedirect()
        ->assertSessionHasErrors('instance');

    Http::assertNothingSent();
    expect(session()->has('mastodon_client_secret'))->toBeFalse();
})->with([
    'loopback' => 'http://127.0.0.1:8080',
    'cloud metadata' => 'http://169.254.169.254',
    'private range' => 'https://10.0.0.5',
]);

test('mastodon refuses an instance whose host resolves to a private address', function () {
    fakePublicDns('10.0.0.7');
    Http::fake(['*' => Http::response(['client_id' => 'leaked-id', 'client_secret' => 'leaked-secret'])]);

    $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => 'https://intranet.example'])
        ->assertRedirect()
        ->assertSessionHasErrors('instance');

    Http::assertNothingSent();
});

test('mastodon connects an internal instance when the install allows private networks', function () {
    config()->set('trypost.security.allow_private_network', true);

    Http::fake([
        'https://10.0.0.5/api/v1/apps' => Http::response([
            'client_id' => 'internal-client-id',
            'client_secret' => 'internal-client-secret',
        ]),
    ]);

    $response = $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => 'https://10.0.0.5']);

    expect($response->headers->get('Location'))->toStartWith('https://10.0.0.5/oauth/authorize');
});

test('mastodon connect errors are shown in the user language', function () {
    $this->user->update(['locale' => Locale::PortugueseBrazil]);
    fakePublicDns();
    Http::fake(['https://mastodon.example/api/v1/apps' => Http::response([], 500)]);

    $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => 'https://mastodon.example'])
        ->assertSessionHasErrors(['instance' => 'Não foi possível conectar a esta instância do Mastodon.']);

    Http::fake(['https://mastodon.example/api/v1/apps' => fn () => throw new RuntimeException('boom')]);

    $this->actingAs($this->user)
        ->post(route('app.social.mastodon.authorize'), ['instance' => 'https://mastodon.example'])
        ->assertSessionHasErrors(['instance' => 'Erro ao conectar à instância do Mastodon.']);
});

test('notifications are asked for only while the mastodon inbox is on', function (bool $inboxOn, string $scopes) {
    config()->set('trypost.inbox.enabled', $inboxOn);
    Http::fake([
        'https://mastodon.social/api/v1/apps' => Http::response(['client_id' => 'id', 'client_secret' => 'secret']),
    ]);

    $this->actingAs($this->user)->post(route('app.social.mastodon.authorize'), ['instance' => 'https://mastodon.social']);

    Http::assertSent(fn (ClientRequest $request): bool => $request['scopes'] === $scopes);
})->with([
    'inbox off' => [false, 'read:accounts read:statuses write:statuses write:media'],
    'inbox on' => [true, 'read:accounts read:statuses write:statuses write:media read:notifications'],
]);
