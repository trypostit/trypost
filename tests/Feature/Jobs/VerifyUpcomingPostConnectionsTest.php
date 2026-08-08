<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Mail\PostAtRisk;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Support\Facades\Mail;

test('marks the account expired and queues a notification when verify throws TokenExpiredException', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(45),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($workspace, $postPlatform) {
        return $mail->workspace->id === $workspace->id
            && $mail->atRiskGroups->count() === 1
            && $mail->atRiskGroups->first()['postPlatforms']->pluck('id')->contains($postPlatform->id);
    });
});

test('verifies a distinct account only once even with multiple at-risk posts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $postPlatforms = collect(range(1, 3))->map(function (int $i) use ($workspace, $account) {
        $post = Post::factory()->scheduled()->create([
            'workspace_id' => $workspace->id,
            'scheduled_at' => now()->addMinutes(20 * $i),
        ]);

        return PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'status' => PostPlatformStatus::Pending,
        ]);
    });

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    foreach ($postPlatforms as $postPlatform) {
        expect($postPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    }

    Mail::assertQueued(PostAtRisk::class, fn ($mail) => $mail->atRiskGroups->first()['postPlatforms']->count() === 3);
});

test('ignores posts outside the 1-hour window', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addHours(3),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    Mail::assertNothingQueued();
});

test('ignores posts already warned', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => now()->subMinutes(5),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    Mail::assertNothingQueued();
});

test('does not re-verify an account already known token_expired, but still warns about new posts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
        'error_message' => 'Threads access token is invalid or expired',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    Mail::assertQueued(PostAtRisk::class);
});

test('does not warn and does not mark connection_warning_sent_at on PlatformUnavailableException', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new PlatformUnavailableException('Threads API returned 503'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('does nothing when the account verifies successfully', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::Connected);
    Mail::assertNothingQueued();
});
