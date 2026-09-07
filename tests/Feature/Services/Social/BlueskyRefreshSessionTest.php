<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Http;

test('bluesky refresh posts refreshSession with no request body', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $workspace->id,
        'refresh_token' => 'refresh-jwt',
    ]);

    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.refreshSession" => Http::response([
            'accessJwt' => 'new-access',
            'refreshJwt' => 'new-refresh',
        ], 200),
    ]);

    app(ConnectionVerifier::class)->refreshToken($account);

    // bsky.social rejects refreshSession when any body is present — even the
    // empty JSON object/array a data-less post() would send.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'refreshSession')
            && $request->body() === '';
    });

    expect($account->fresh()->access_token)->toBe('new-access');
});
