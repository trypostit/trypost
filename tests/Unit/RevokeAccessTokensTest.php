<?php

declare(strict_types=1);

use App\Actions\AccessToken\RevokeAccessTokens;
use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('revokes access tokens and their refresh tokens', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $token = mcpAccessToken($user, mcpOauthClient(), $workspace);
    $refreshId = Str::random(80);

    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshId,
        'access_token_id' => $token->id,
        'revoked' => false,
        'expires_at' => now()->addMonth(),
    ]);

    RevokeAccessTokens::execute($token);

    expect(AccessToken::query()->find($token->id)->revoked)->toBeTrue();
    expect(DB::table('oauth_refresh_tokens')->where('id', $refreshId)->value('revoked'))->toBeTrue();
});

test('ignores already revoked tokens without error', function () {
    $user = User::factory()->create();
    $token = mcpAccessToken($user, mcpOauthClient());
    $token->forceFill(['revoked' => true])->saveQuietly();

    RevokeAccessTokens::execute([$token]);

    expect(AccessToken::query()->find($token->id)->revoked)->toBeTrue();
});
