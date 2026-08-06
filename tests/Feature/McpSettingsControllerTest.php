<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    // SaaS mode: the MCP settings live behind EnsureAccountReady.
    subscribeAccount($this->user->account);
});

it('shows the mcp settings page', function (): void {
    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Mcp')
            ->where('mcpUrl', route('mcp.trypost'))
            ->missing('docsUrl')
            ->missing('mcpClients')
            ->has('connectedClients'));
});

it('shows the mcp settings page without a subscription in self-hosted mode', function (): void {
    config(['trypost.self_hosted' => true]);
    $this->user->account->subscriptions()->delete();

    $this->actingAs($this->user->fresh())
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Mcp')
            ->where('mcpUrl', route('mcp.trypost')));
});

it('lists only the current users oauth clients as connected, excluding personal access tokens', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $ownerClientId = mcpOauthClient('Owner Agent');
    mcpAccessToken($this->user, $ownerClientId);

    $memberClientId = mcpOauthClient('Member Agent');
    mcpAccessToken($member, $memberClientId);

    $pat = $this->user->createToken('API Key');
    AccessToken::query()->findOrFail($pat->token->id)
        ->forceFill(['workspace_id' => $this->workspace->id])
        ->saveQuietly();

    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Owner Agent')
            ->where('connectedClients.0.can_disconnect', true)
            ->where('connectedClients', fn ($clients): bool => collect($clients)->every(
                fn (array $client): bool => $client['name'] !== 'Member Agent',
            )));
});

it('excludes unscoped oauth grants from connected clients', function (): void {
    mcpAccessToken($this->user, mcpOauthClient('Unscoped Agent'), scopes: []);

    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertInertia(fn ($page) => $page->where('connectedClients', []));
});

it('lists viewer own oauth grants as connected clients', function (): void {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    mcpAccessToken($viewer, mcpOauthClient('Viewer Agent'));

    $this->actingAs($viewer->fresh())
        ->get(route('app.mcp.index'))
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Viewer Agent')
            ->where('connectedClients.0.can_disconnect', true));
});

it('disconnects a client by revoking its tokens', function (): void {
    $clientId = mcpOauthClient();
    $token = mcpAccessToken($this->user, $clientId);

    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionHas('flash.success', __('mcp.disconnected'));

    expect($token->fresh()->revoked)->toBeTrue();
});

it('disconnects a client when its access token expired but its refresh token is live', function (): void {
    $clientId = mcpOauthClient();
    $token = mcpAccessToken($this->user, $clientId);
    $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    $refreshTokenId = Str::random(80);
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshTokenId,
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($token->fresh()->revoked)->toBeTrue()
        ->and(DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->value('revoked'))->toBeTrue();
});

it('lists a client when its access token expired but its refresh token is live', function (): void {
    $clientId = mcpOauthClient('Recoverable Agent');
    $token = mcpAccessToken($this->user, $clientId);
    $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Recoverable Agent')
            ->where('connectedClients.0.can_disconnect', true));
});

it('hides a client when both access and refresh tokens are expired', function (): void {
    $clientId = mcpOauthClient('Dead Agent');
    $token = mcpAccessToken($this->user, $clientId);
    $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();
    DB::table('oauth_refresh_tokens')->insert([
        'id' => Str::random(80),
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('connectedClients', []));
});

it('does not flash success when disconnecting an unknown client', function (): void {
    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => (string) Str::uuid()]))
        ->assertRedirect()
        ->assertSessionMissing('flash.success');
});

it('does not list a teammates mcp connection', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    mcpAccessToken($this->user, mcpOauthClient('Owner Agent'));

    $this->actingAs($member->fresh())
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('connectedClients', []));
});

it('allows workspace members to view and disconnect their own mcp clients', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $clientId = mcpOauthClient('Member Agent');
    $token = mcpAccessToken($member, $clientId);

    $this->actingAs($member->fresh())
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Member Agent'));

    $this->actingAs($member->fresh())
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($token->fresh()->revoked)->toBeTrue();
});

it('allows workspace viewers to view and disconnect their own mcp clients', function (): void {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $clientId = mcpOauthClient('Viewer Agent');
    $token = mcpAccessToken($viewer, $clientId);

    $this->actingAs($viewer->fresh())
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Mcp')
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Viewer Agent')
            ->where('connectedClients.0.can_disconnect', true));

    $this->actingAs($viewer->fresh())
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($token->fresh()->revoked)->toBeTrue();
});

it('does not revoke another users mcp client tokens', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $clientId = mcpOauthClient('Owner Agent');
    $token = mcpAccessToken($this->user, $clientId);

    $this->actingAs($member->fresh())
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionMissing('flash.success');

    expect($token->fresh()->revoked)->toBeFalse();
});

it('does not revoke personal access tokens via disconnect', function (): void {
    $pat = $this->user->createToken('API Key');
    $token = AccessToken::query()->findOrFail($pat->token->id);
    $token->forceFill(['workspace_id' => $this->workspace->id])->saveQuietly();

    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => $token->client_id]))
        ->assertRedirect()
        ->assertSessionMissing('flash.success');

    expect($token->fresh()->revoked)->toBeFalse();
});

it('requires authentication', function (): void {
    $this->get(route('app.mcp.index'))->assertRedirect();
});

it('forbids users without workspace access', function (): void {
    $outsider = User::factory()->create();
    subscribeAccount($outsider->account);
    $outsider->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($outsider->fresh())
        ->get(route('app.mcp.index'))
        ->assertForbidden();
});

it('redirects to welcome when the account has no app access', function (): void {
    $this->user->account->subscriptions()->delete();

    $this->actingAs($this->user->fresh())
        ->get(route('app.mcp.index'))
        ->assertRedirect(route('app.welcome.persona'));
});
