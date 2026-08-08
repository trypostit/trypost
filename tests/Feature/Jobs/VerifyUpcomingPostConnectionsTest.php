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
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
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

test('defers to the next run instead of warning when markAsTokenExpired loses the account status lock', function () {
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

    // Simulate a concurrent process (e.g. a publish attempt) already holding
    // this account's status lock, so markAsTokenExpired() can't acquire it
    // and silently no-ops.
    $lock = Cache::lock("social_account_status:{$account->id}", 10);
    $lock->get();

    try {
        VerifyUpcomingPostConnections::dispatchSync($workspace->id);

        expect($account->fresh()->status)->toBe(SocialAccountStatus::Connected);
        expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
        Mail::assertNothingQueued();
    } finally {
        $lock->release();
    }
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

test('ignores draft posts even with a scheduled_at inside the window', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
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
    expect($account->fresh()->status)->toBe(SocialAccountStatus::Connected);
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

test('an unexpected exception verifying one account does not abort the run for other accounts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    $brokenAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $brokenPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $brokenPostPlatform = PostPlatform::factory()->create([
        'post_id' => $brokenPost->id,
        'social_account_id' => $brokenAccount->id,
        'platform' => $brokenAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $expiredAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $expiredPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(40),
    ]);
    $expiredPostPlatform = PostPlatform::factory()->create([
        'post_id' => $expiredPost->id,
        'social_account_id' => $expiredAccount->id,
        'platform' => $expiredAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::on(fn ($account) => $account->id === $brokenAccount->id))
        ->andThrow(new ConnectionException('Could not resolve host'));
    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::on(fn ($account) => $account->id === $expiredAccount->id))
        ->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($brokenPostPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    expect($brokenAccount->fresh()->status)->toBe(SocialAccountStatus::Connected);

    expect($expiredPostPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($expiredAccount->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($expiredPostPlatform) {
        return $mail->atRiskGroups->count() === 1
            && $mail->atRiskGroups->first()['postPlatforms']->pluck('id')->contains($expiredPostPlatform->id);
    });
});

test('ignores disabled platforms even inside the risk window', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->disabled()->create([
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

test('does not leak another workspace\'s at-risk posts into this workspace\'s notification', function () {
    Mail::fake();

    $workspaceA = Workspace::factory()->create();
    $accountA = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspaceA->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $postA = Post::factory()->scheduled()->create([
        'workspace_id' => $workspaceA->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $postPlatformA = PostPlatform::factory()->create([
        'post_id' => $postA->id,
        'social_account_id' => $accountA->id,
        'platform' => $accountA->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $workspaceB = Workspace::factory()->create();
    $accountB = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspaceB->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $postB = Post::factory()->scheduled()->create([
        'workspace_id' => $workspaceB->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $postPlatformB = PostPlatform::factory()->create([
        'post_id' => $postB->id,
        'social_account_id' => $accountB->id,
        'platform' => $accountB->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::on(fn ($account) => $account->id === $accountA->id))
        ->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    $verifier->shouldNotReceive('verify')
        ->with(Mockery::on(fn ($account) => $account->id === $accountB->id));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspaceA->id);

    expect($postPlatformA->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($postPlatformB->fresh()->connection_warning_sent_at)->toBeNull();
    expect($accountB->fresh()->status)->toBe(SocialAccountStatus::Connected);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($workspaceA, $postPlatformA, $postPlatformB) {
        $postPlatformIds = $mail->atRiskGroups->flatMap(fn (array $group) => $group['postPlatforms']->pluck('id'));

        return $mail->workspace->id === $workspaceA->id
            && $postPlatformIds->contains($postPlatformA->id)
            && ! $postPlatformIds->contains($postPlatformB->id);
    });

    Mail::assertQueuedCount(1);
});

test('re-evaluates a post_platform warned more than a day ago instead of skipping it forever', function () {
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
        'connection_warning_sent_at' => now()->subDay()->subMinute(),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->not->toBeNull()
        ->and($postPlatform->fresh()->connection_warning_sent_at->isAfter(now()->subMinute()))->toBeTrue();

    Mail::assertQueued(PostAtRisk::class);
});

test('still skips a post_platform warned less than a day ago', function () {
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
    $warnedAt = now()->subHours(2);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => $warnedAt,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at->format('Y-m-d H:i:s'))
        ->toBe($warnedAt->format('Y-m-d H:i:s'));
    Mail::assertNothingQueued();
});

test('does not re-warn a re-armed post_platform when the account has since been reconnected', function () {
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
    $warnedAt = now()->subDay()->subMinute();
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => $warnedAt,
    ]);

    // Old warning is stale enough to re-arm the row, but this time the
    // account verifies fine — the user reconnected in the meantime.
    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    // The row was re-evaluated (not skipped — verify() was called), but
    // since it came back healthy, nothing about it changes: no new
    // warning, no notification, no touch to the account's status.
    expect($postPlatform->fresh()->connection_warning_sent_at->format('Y-m-d H:i:s'))
        ->toBe($warnedAt->format('Y-m-d H:i:s'));
    expect($account->fresh()->status)->toBe(SocialAccountStatus::Connected);
    Mail::assertNothingQueued();
});

test('does not crash the run on a post_platform with a null social_account_id', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    $orphanedPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $orphanedPost->id,
        'social_account_id' => null,
        'status' => PostPlatformStatus::Pending,
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(40),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($postPlatform) {
        return $mail->atRiskGroups->count() === 1
            && $mail->atRiskGroups->first()['postPlatforms']->pluck('id')->contains($postPlatform->id);
    });
});

test('leaves posts unwarned and does not send an email when the workspace has no owner', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create(['user_id' => null]);
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
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('job is unique per workspace with a window covering its timeout', function () {
    $job = new VerifyUpcomingPostConnections('workspace-uuid');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('workspace-uuid')
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout);
});
