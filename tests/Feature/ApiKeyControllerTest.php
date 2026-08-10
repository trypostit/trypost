<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PassportSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

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

it('excludes workspace-bound mcp oauth grants from the api keys page', function () {
    makeWorkspaceToken($this->user, $this->workspace);
    $oauth = mcpAccessToken($this->user, mcpOauthClient('Claude'), $this->workspace);

    $this->actingAs($this->user)
        ->get(route('app.api-keys.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('apiTokens', 1)
            ->where('apiTokens', fn ($tokens): bool => collect($tokens)->every(
                fn (array $token): bool => $token['id'] !== $oauth->id,
            )));
});

it('cannot delete a workspace-bound mcp oauth grant through api keys', function () {
    $oauth = mcpAccessToken($this->user, mcpOauthClient('Claude'), $this->workspace);

    $this->actingAs($this->user)
        ->delete(route('app.api-keys.destroy', $oauth->id))
        ->assertNotFound();

    expect($oauth->fresh()->revoked)->toBeFalse();
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
    expect($tokens->first()->expires_at)->toBeNull();
});

it('bootstraps the personal access client idempotently', function () {
    DB::table('oauth_clients')
        ->whereJsonContains('grant_types', 'personal_access')
        ->delete();

    $seeder = app(PassportSeeder::class);
    $clients = app(ClientRepository::class);

    $seeder->run($clients);
    $seeder->run($clients);

    expect(DB::table('oauth_clients')
        ->whereJsonContains('grant_types', 'personal_access')
        ->count())->toBe(1);

    expect($this->user->createToken('Self-hosted API Key')->accessToken)->toBeString();
});

it('creates an api key with expiration', function () {
    $expiresAt = now()->addDays(30)->startOfDay();

    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [
            'name' => 'Expiring Key',
            'expires_at' => $expiresAt->format('Y-m-d'),
        ])
        ->assertRedirect();

    $token = AccessToken::where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->first();

    expect($token->expires_at)->not->toBeNull()
        ->and($token->expires_at->toDateString())->toBe($expiresAt->toDateString())
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
});

it('validates name is required', function () {
    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [])
        ->assertSessionHasErrors('name');
});

it('rejects an expiration in the past', function () {
    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [
            'name' => 'Past Key',
            'expires_at' => now()->subDay()->toDateString(),
        ])
        ->assertSessionHasErrors('expires_at');
});

it('allows an expiration of today', function () {
    $today = now()->toDateString();

    $this->actingAs($this->user)
        ->post(route('app.api-keys.store'), [
            'name' => 'Expires Today',
            'expires_at' => $today,
        ])
        ->assertRedirect();

    $token = AccessToken::where('user_id', $this->user->id)
        ->where('workspace_id', $this->workspace->id)
        ->where('name', 'Expires Today')
        ->firstOrFail();

    expect($token->expires_at->toDateString())->toBe($today)
        ->and($token->expires_at->format('H:i:s'))->toBe('23:59:59');
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
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)
        ->post(route('app.api-keys.store'), ['name' => 'Test Key'])
        ->assertForbidden();
});

it('member cannot delete api key', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
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
