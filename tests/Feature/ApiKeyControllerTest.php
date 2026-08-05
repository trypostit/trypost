<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
});

function makeWorkspaceToken(User $user, Workspace $workspace): AccessToken
{
    $result = $user->createToken('Existing');
    $token = AccessToken::find($result->token->id);
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    return $token->refresh();
}

it('shows api keys page', function () {
    makeWorkspaceToken($this->user, $this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.api-keys.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/ApiKeys')
            ->has('apiTokens', 1)
        );
});

it('creates an api key', function () {
    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), ['name' => 'My API Key'])
        ->assertRedirect();

    $tokens = AccessToken::where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->name)->toBe('My API Key');
    expect($tokens->first()->revoked)->toBeFalse();
});

it('creates an api key with expiration', function () {
    $expiresOn = now()->addDays(30)->format('Y-m-d');

    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [
            'name' => 'Expiring Key',
            'expires_at' => $expiresOn,
        ])
        ->assertRedirect();

    $token = AccessToken::where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->first();

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->toDateString())->toBe($expiresOn);
});

it('passes the chosen expiry calendar day to the api keys page', function () {
    $expiresOn = '2026-10-31';

    $result = $this->user->createToken('Expiring Key');
    $token = AccessToken::find($result->token->id);
    $token->forceFill([
        'workspace_id' => $this->workspace->id,
        'expires_at' => $expiresOn,
    ])->saveQuietly();

    $this->actingAs($this->user)
        ->get(route('app.api-keys.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/ApiKeys')
            ->has('apiTokens', 1)
            ->where('apiTokens.0.name', 'Expiring Key')
            ->where('apiTokens.0.expires_at', fn ($value) => is_string($value) && str_starts_with($value, $expiresOn))
        );
});

it('validates name is required', function () {
    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [])
        ->assertSessionHasErrors('name');
});

it('revokes an api key', function () {
    $token = makeWorkspaceToken($this->user, $this->workspace);

    $this->actingAs($this->user)
        ->delete(route('app.api-keys.destroy', $token->id))
        ->assertRedirect();

    expect($token->refresh()->revoked)->toBeTrue();
});

it('cannot delete api key from another workspace', function () {
    $otherUser = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $otherUser->account_id,
        'user_id' => $otherUser->id,
    ]);
    $token = makeWorkspaceToken($otherUser, $otherWorkspace);

    $this->actingAs($this->user)
        ->delete(route('app.api-keys.destroy', $token->id))
        ->assertNotFound();
});

it('member cannot create api key', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)
        ->post(route('app.api-keys.store'), ['name' => 'Test Key'])
        ->assertForbidden();
});

it('member cannot delete api key', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $token = makeWorkspaceToken($this->user, $this->workspace);

    $this->actingAs($member)
        ->delete(route('app.api-keys.destroy', $token->id))
        ->assertForbidden();
});

it('api keys page requires authentication', function () {
    $this->get(route('app.api-keys.index'))->assertRedirect(route('login'));
});
