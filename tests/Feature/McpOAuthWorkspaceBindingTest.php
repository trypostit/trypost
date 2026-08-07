<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AccessTokenRepository;
use App\Passport\AuthCode;
use App\Passport\AuthCodeRepository;
use App\Passport\OAuthPayloadDecryptor;
use Illuminate\Support\Str;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
    $this->clientId = mcpOauthClient();
    $this->decryptor = app(OAuthPayloadDecryptor::class);
});

test('authorization code grant binds workspace from the encrypted auth code payload', function () {
    $authCodeId = Str::random(80);
    $tokenId = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $authCodeId,
        'user_id' => $this->user->id,
        'client_id' => $this->clientId,
        'workspace_id' => $this->workspace->id,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    // User switched workspace between consent and token exchange — auth code wins.
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'auth_code_id' => $authCodeId,
            'user_id' => (string) $this->user->id,
            'scopes' => [],
            'expire_time' => now()->addMinutes(10)->timestamp,
        ]),
    ]);

    persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId);

    $token = AccessToken::query()->findOrFail($tokenId);

    expect($token->workspace_id)->toBe($this->workspace->id);
});

test('refresh grant inherits workspace from the refreshed access token id', function () {
    $previous = mcpAccessToken($this->user, $this->clientId, $this->workspace);
    $tokenId = Str::random(80);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);

    // Newer grant on another workspace for the same client must not win.
    mcpAccessToken($this->user, $this->clientId, $otherWorkspace);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    request()->merge([
        'grant_type' => 'refresh_token',
        'refresh_token' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'refresh_token_id' => Str::random(80),
            'access_token_id' => $previous->id,
            'scopes' => [],
            'user_id' => (string) $this->user->id,
            'expire_time' => now()->addMonth()->timestamp,
        ]),
    ]);

    persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId);

    $refreshed = AccessToken::query()->findOrFail($tokenId);

    expect($refreshed->workspace_id)->toBe($previous->workspace_id)
        ->and($refreshed->workspace_id)->not->toBe($otherWorkspace->id);
});

test('refresh grant fails when the user no longer belongs to the bound workspace', function () {
    $previous = mcpAccessToken($this->user, $this->clientId, $this->workspace);
    $tokenId = Str::random(80);

    $this->workspace->members()->detach($this->user->id);

    request()->merge([
        'grant_type' => 'refresh_token',
        'refresh_token' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'refresh_token_id' => Str::random(80),
            'access_token_id' => $previous->id,
            'scopes' => [],
            'user_id' => (string) $this->user->id,
            'expire_time' => now()->addMonth()->timestamp,
        ]),
    ]);

    expect(fn () => persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId))
        ->toThrow(OAuthServerException::class);

    expect(AccessToken::query()->find($tokenId))->toBeNull();
});

test('unexpected grant types fail closed instead of using the current workspace', function () {
    $tokenId = Str::random(80);

    request()->merge(['grant_type' => 'client_credentials']);

    expect(fn () => persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId))
        ->toThrow(OAuthServerException::class);

    expect(AccessToken::query()->find($tokenId))->toBeNull();
});

test('authorization code grant fails when the user left the auth code workspace before exchange', function () {
    $authCodeId = Str::random(80);
    $tokenId = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $authCodeId,
        'user_id' => $this->user->id,
        'client_id' => $this->clientId,
        'workspace_id' => $this->workspace->id,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    $this->workspace->members()->detach($this->user->id);

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'auth_code_id' => $authCodeId,
            'user_id' => (string) $this->user->id,
            'scopes' => [],
            'expire_time' => now()->addMinutes(10)->timestamp,
        ]),
    ]);

    expect(fn () => persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId))
        ->toThrow(OAuthServerException::class);

    expect(AccessToken::query()->find($tokenId))->toBeNull();
});

test('personal access tokens are left alone for controllers to bind', function () {
    $result = $this->user->createToken('PAT');
    $token = AccessToken::query()->find($result->token->id);

    expect($token->workspace_id)->toBeNull();
});

test('auth code repository captures workspace_id from the consent form', function () {
    $this->actingAs($this->user);
    request()->merge(['workspace_id' => $this->workspace->id]);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored)->not->toBeNull();
    expect($stored->workspace_id)->toBe($this->workspace->id);
});

test('auth code repository prefers workspace_id from the consent form', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace B',
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);

    // Current workspace stays A; consent form picks B.
    $this->actingAs($this->user);
    request()->merge(['workspace_id' => $otherWorkspace->id]);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBe($otherWorkspace->id);
    expect($this->user->fresh()->current_workspace_id)->toBe($this->workspace->id);
});

test('auth code repository rejects a consent workspace the user does not belong to', function () {
    $foreign = Workspace::factory()->create();

    $this->actingAs($this->user);
    request()->merge(['workspace_id' => $foreign->id]);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBeNull();
});

test('auth code repository requires workspace_id from the consent form', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace B',
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);
    $this->actingAs($this->user->fresh());
    // No workspace_id in the request — current workspace must not be used.

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBeNull();
});

test('auth code repository rejects a consent workspace the user no longer belongs to', function () {
    $this->workspace->members()->detach($this->user->id);
    $this->actingAs($this->user->fresh());
    request()->merge(['workspace_id' => $this->workspace->id]);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored->workspace_id)->toBeNull();
});

test('oauth grant without a resolvable workspace fails before the token is saved', function () {
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->members()->detach($this->user->id);

    $tokenId = Str::random(80);

    request()->merge(['grant_type' => 'authorization_code']);

    expect(fn () => persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId))
        ->toThrow(OAuthServerException::class);

    expect(AccessToken::query()->find($tokenId))->toBeNull();
});

test('authorization code grant does not fall back to the users current workspace', function () {
    $authCodeId = Str::random(80);
    $tokenId = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $authCodeId,
        'user_id' => $this->user->id,
        'client_id' => $this->clientId,
        'workspace_id' => null,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => $this->decryptor->encrypt([
            'client_id' => $this->clientId,
            'auth_code_id' => $authCodeId,
            'user_id' => (string) $this->user->id,
            'scopes' => [],
            'expire_time' => now()->addMinutes(10)->timestamp,
        ]),
    ]);

    expect(fn () => persistAccessTokenEntity($tokenId, (string) $this->user->id, $this->clientId))
        ->toThrow(OAuthServerException::class);

    expect(AccessToken::query()->find($tokenId))->toBeNull();
});

function persistAccessTokenEntity(string $tokenId, string $userId, string $clientId): void
{
    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($clientId);

    $entity = Mockery::mock(AccessTokenEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn($tokenId);
    $entity->shouldReceive('getUserIdentifier')->andReturn($userId);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addHour()->toDateTimeImmutable());

    app(AccessTokenRepository::class)->persistNewAccessToken($entity);
}
