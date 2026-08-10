<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

function createApiKeyApiToken(array $overrides = []): array
{
    return createApiTestToken($overrides);
}

test('list api keys', function () {
    $result = createApiKeyApiToken();

    foreach (['Second', 'Third'] as $name) {
        $token = $result['user']->createToken($name)->token;
        AccessToken::query()->findOrFail($token->id)
            ->forceFill(['workspace_id' => $result['workspace']->id])
            ->saveQuietly();
    }

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->getJson(
        route('api.api-keys.index'),
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertOk();
    $response->assertJsonCount(3);
});

test('list api keys excludes revoked and other-workspace tokens', function () {
    $result = createApiKeyApiToken();
    $revoked = $result['user']->createToken('Revoked')->token;
    AccessToken::query()->findOrFail($revoked->id)
        ->forceFill([
            'workspace_id' => $result['workspace']->id,
            'revoked' => true,
        ])
        ->saveQuietly();

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $result['user']->account_id,
        'user_id' => $result['user']->id,
    ]);
    $other = $result['user']->createToken('Other workspace')->token;
    AccessToken::query()->findOrFail($other->id)
        ->forceFill(['workspace_id' => $otherWorkspace->id])
        ->saveQuietly();

    $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->getJson(route('api.api-keys.index'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['id' => $revoked->id])
        ->assertJsonMissing(['id' => $other->id]);
});

test('list api keys excludes workspace-bound mcp oauth grants', function () {
    $result = createApiKeyApiToken();
    $oauth = mcpAccessToken($result['user'], mcpOauthClient('Claude'), $result['workspace']);

    $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->getJson(route('api.api-keys.index'))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonMissing(['id' => $oauth->id]);
});

test('cannot delete a workspace-bound mcp oauth grant through the api', function () {
    $result = createApiKeyApiToken();
    $oauth = mcpAccessToken($result['user'], mcpOauthClient('Claude'), $result['workspace']);

    $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->deleteJson(route('api.api-keys.destroy', $oauth->id))
        ->assertNotFound();

    expect($oauth->fresh()->revoked)->toBeFalse();
});

test('create api key returns plain token', function () {
    $result = createApiKeyApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.api-keys.store'),
        ['name' => 'CI/CD Token'],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertCreated();
    $response->assertJsonStructure([
        'token' => ['id', 'name', 'created_at'],
        'plain_token',
    ]);

    expect($response->json('plain_token'))->toBeString();
});

test('workspace members cannot manage api keys through the api', function () {
    $result = createApiKeyApiToken();
    $member = User::factory()->create(['account_id' => $result['user']->account_id]);
    $result['workspace']->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $result['workspace']->id]);
    $plainToken = passportToken($member, $result['workspace']);

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->getJson(route('api.api-keys.index'))
        ->assertForbidden();

    $this->withHeaders(['Authorization' => "Bearer {$plainToken}"])
        ->postJson(route('api.api-keys.store'), ['name' => 'Escalation Key'])
        ->assertForbidden();
});

test('create api key validation errors', function () {
    $result = createApiKeyApiToken();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->postJson(
        route('api.api-keys.store'),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['name']);
});

test('delete api key', function () {
    $result = createApiKeyApiToken();

    $tokenToDelete = $result['user']->createToken('To delete')->token;
    AccessToken::find($tokenToDelete->id)
        ->forceFill(['workspace_id' => $result['workspace']->id])
        ->saveQuietly();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->deleteJson(
        route('api.api-keys.destroy', $tokenToDelete->id),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNoContent();
    expect(AccessToken::find($tokenToDelete->id)->revoked)->toBeTrue();
});

test('cannot delete api key from another workspace', function () {
    $result = createApiKeyApiToken();

    $otherWorkspace = Workspace::factory()->create();
    $otherUser = $otherWorkspace->owner ?? User::factory()->create([
        'account_id' => $otherWorkspace->account_id,
    ]);
    $otherToken = $otherUser->createToken('Other')->token;
    AccessToken::find($otherToken->id)
        ->forceFill(['workspace_id' => $otherWorkspace->id])
        ->saveQuietly();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.$result['plain_token'],
    ])->deleteJson(
        route('api.api-keys.destroy', $otherToken->id),
        [],
        ['HTTP_HOST' => 'api.trypost.test']
    );

    $response->assertNotFound();
});

it('validates api key expires_at must be future date', function () {
    $result = createApiKeyApiToken();

    $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->postJson(route('api.api-keys.store'), [
            'name' => 'Test Key',
            'expires_at' => '2020-01-01',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expires_at']);
});

it('creates an api key without expiration', function () {
    $result = createApiKeyApiToken();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->postJson(route('api.api-keys.store'), [
            'name' => 'Never Expires',
        ])
        ->assertCreated();

    expect($response->json('token.expires_at'))->toBeNull()
        ->and(AccessToken::query()->findOrFail($response->json('token.id'))->expires_at)->toBeNull();
});

it('creates an api key with expiration at end of day', function () {
    $result = createApiKeyApiToken();
    $expiresAt = now()->addDays(14)->startOfDay();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->postJson(route('api.api-keys.store'), [
            'name' => 'Expiring Key',
            'expires_at' => $expiresAt->toDateString(),
        ])
        ->assertCreated();

    $token = AccessToken::query()->findOrFail($response->json('token.id'));

    expect($token->expires_at->toDateString())->toBe($expiresAt->toDateString())
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
});

it('allows an api key expiration of today', function () {
    $result = createApiKeyApiToken();
    $today = now()->toDateString();

    $response = $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->postJson(route('api.api-keys.store'), [
            'name' => 'Expires Today',
            'expires_at' => $today,
        ])
        ->assertCreated();

    $token = AccessToken::query()->findOrFail($response->json('token.id'));

    expect($token->expires_at->toDateString())->toBe($today)
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
});

it('validates api key name max length', function () {
    $result = createApiKeyApiToken();

    $this->withHeaders(['Authorization' => 'Bearer '.$result['plain_token']])
        ->postJson(route('api.api-keys.store'), [
            'name' => str_repeat('a', 256),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});
