<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\VerifyWorkspaceConnections;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

test('job does nothing when workspace has no connected accounts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    VerifyWorkspaceConnections::dispatch($workspace);

    Mail::assertNothingSent();
});

test('job does not send email when all connections are valid', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andReturn(true);

    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    Mail::assertNothingSent();
});

test('job marks account as token expired on first failure and disconnected on second', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->andThrow(new TokenExpiredException('Token expired'));

    app()->instance(ConnectionVerifier::class, $verifier);

    // First run — marks as TokenExpired
    VerifyWorkspaceConnections::dispatch($workspace);

    expect($account->fresh()->status)->toBe(Status::TokenExpired);
    expect($account->fresh()->error_message)->toBe('Token expired');

    // Second run — escalates to Disconnected
    VerifyWorkspaceConnections::dispatch($workspace);

    expect($account->fresh()->status)->toBe(Status::Disconnected);

    Mail::assertQueued(WorkspaceConnectionsDisconnected::class, function ($mail) use ($workspace) {
        return $mail->workspace->id === $workspace->id
            && $mail->disconnectedAccounts->count() === 1;
    });
});

test('job sends single email with all failed accounts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account1 = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $account2 = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->andThrow(new TokenExpiredException('Token expired'));

    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    expect($account1->fresh()->status)->toBe(Status::TokenExpired);
    expect($account2->fresh()->status)->toBe(Status::TokenExpired);

    Mail::assertQueued(WorkspaceConnectionsDisconnected::class, function ($mail) use ($workspace) {
        return $mail->workspace->id === $workspace->id
            && $mail->disconnectedAccounts->count() === 2;
    });

    Mail::assertQueuedCount(1);
});

test('job only includes failed accounts in email', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $validAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $invalidAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->with(Mockery::on(fn ($acc) => $acc->id === $validAccount->id))
        ->andReturn(true);
    $verifier->shouldReceive('verify')
        ->with(Mockery::on(fn ($acc) => $acc->id === $invalidAccount->id))
        ->andThrow(new TokenExpiredException('Token expired'));

    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    expect($validAccount->fresh()->status)->toBe(Status::Connected);
    expect($invalidAccount->fresh()->status)->toBe(Status::TokenExpired);

    Mail::assertQueued(WorkspaceConnectionsDisconnected::class, function ($mail) use ($invalidAccount) {
        return $mail->disconnectedAccounts->count() === 1
            && $mail->disconnectedAccounts->first()->id === $invalidAccount->id;
    });
});

test('job does NOT disconnect or email when platform is unavailable', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(
        new PlatformUnavailableException('Bluesky API returned 503 during token refresh', 503)
    );

    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    expect($account->fresh()->status)->toBe(Status::Connected);
    Mail::assertNothingQueued();
});

test('job skips already disconnected accounts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->linkedin()->disconnected()->create(['workspace_id' => $workspace->id]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');

    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    Mail::assertNothingSent();
});

test('daily sweep skips verifying a connected account a recent refresh already proved valid', function () {
    Mail::fake();
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
        'last_verified_at' => now()->subHours(2),
    ]);

    VerifyWorkspaceConnections::dispatch($workspace);

    // A successful token refresh within the trust window already proved the
    // credential — re-reading the profile would only burn a billed User Read.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/users/me'));
    Mail::assertNothingSent();
});

test('daily sweep still verifies a connected account whose last_verified_at is stale', function () {
    Mail::fake();
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
        'last_verified_at' => now()->subHours(20),
    ]);

    VerifyWorkspaceConnections::dispatch($workspace);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me'));
});

test('daily sweep still verifies a TokenExpired account despite a fresh last_verified_at', function () {
    Mail::fake();
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::TokenExpired,
        'last_verified_at' => now()->subMinutes(5),
    ]);

    VerifyWorkspaceConnections::dispatch($workspace);

    // Skipping here would strand a recovered account in TokenExpired forever.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/users/me'));
    expect($account->fresh()->status)->toBe(Status::Connected);
});

test('daily sweep records its own successful verification', function () {
    Mail::fake();
    Http::fake([
        config('trypost.platforms.x.api').'/users/me' => Http::response(['data' => ['id' => '123']], 200),
    ]);

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::Connected,
        'last_verified_at' => null,
    ]);

    VerifyWorkspaceConnections::dispatch($workspace);

    // Otherwise VerifyUpcomingPostConnections burns a fresh call minutes later
    // on an account this sweep just confirmed healthy.
    expect($account->fresh()->last_verified_at)->not->toBeNull();
});

test('an unreachable platform does not revive an account nobody verified', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $workspace->id,
        'status' => Status::TokenExpired,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->andThrow(new PlatformUnavailableException('X API returned 503', 503));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyWorkspaceConnections::dispatch($workspace);

    // "Don't disconnect" is not the same as "verified". Promoting on an
    // outage tells the owner their reconnect worked when nothing was checked.
    expect($account->fresh()->status)->toBe(Status::TokenExpired);
});
