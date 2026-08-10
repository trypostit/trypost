<?php

declare(strict_types=1);

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Mail\PostAtRisk;
use App\Models\Notification;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        'scheduled_at' => now()->addMinutes(20),
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
            && count($mail->postPlatformIds) === 1
            && in_array($postPlatform->id, $mail->postPlatformIds, true);
    });
});

test('creates an in-app notification for the workspace owner alongside the email', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect(Notification::count())->toBe(1);

    $notification = Notification::first();
    expect($notification->user_id)->toBe($workspace->owner->id)
        ->and($notification->workspace_id)->toBe($workspace->id)
        ->and($notification->type)->toBe(Type::PostAtRisk)
        ->and($notification->channel)->toBe(Channel::Both)
        ->and($notification->title)->toBe('1 upcoming post is at risk');

    // The in-app title and the email subject are built from the same count,
    // captured once at dispatch time — they must never disagree.
    Mail::assertQueued(PostAtRisk::class, fn ($mail) => $mail->count === 1);
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
        'scheduled_at' => now()->addMinutes(20),
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

    Mail::assertQueued(PostAtRisk::class, fn ($mail) => count($mail->postPlatformIds) === 3);
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

test('counts distinct posts, not post_platforms, when one post spans multiple broken accounts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $accountOne = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
        'error_message' => 'Threads access token is invalid or expired',
    ]);
    $accountTwo = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
        'error_message' => 'Facebook access token is invalid or expired',
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $accountOne->id,
        'platform' => $accountOne->platform,
        'status' => PostPlatformStatus::Pending,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $accountTwo->id,
        'platform' => $accountTwo->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    // Two post_platforms, but they belong to the same post — the count
    // must reflect distinct posts, not post_platform rows.
    Mail::assertQueued(PostAtRisk::class, fn ($mail) => $mail->count === 1 && count($mail->postPlatformIds) === 2);
});

test('does not re-verify an account already known disconnected, but still warns about new posts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Disconnected,
        'error_message' => 'Threads account was disconnected',
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

test('does not re-notify about an already-broken account within the cooldown, even for a brand-new at-risk post', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
    ]);

    // A post already warned about 10 minutes ago — still within the 1-hour
    // renotify cooldown for this account.
    $earlierPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(10),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $earlierPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => now()->subMinutes(10),
    ]);

    // A brand-new post that just entered the risk window — never warned.
    $newPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $newPostPlatform = PostPlatform::factory()->create([
        'post_id' => $newPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    // Left unstamped so it's picked up once the cooldown expires, instead of
    // being silently absorbed without ever being mentioned in an email.
    expect($newPostPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('re-notifies about an already-broken account once the cooldown has passed', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
    ]);

    $earlierPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(10),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $earlierPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => now()->subMinutes(90),
    ]);

    $newPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    $newPostPlatform = PostPlatform::factory()->create([
        'post_id' => $newPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($newPostPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    Mail::assertQueued(PostAtRisk::class);
});

test('skips notifying about a freshly-disconnected account to avoid a duplicate with AccountDisconnected', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
        'disconnected_at' => now()->subMinutes(2),
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

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('notifies about an already-broken account once the disconnection grace period has passed', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
        'disconnected_at' => now()->subMinutes(10),
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

test('trusts a recently successful verification and does not re-verify within the same run window', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
        'last_verified_at' => now()->subMinutes(10),
    ]);
    $post = Post::factory()->scheduled()->create([
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

test('re-verifies an account once the last successful verification has aged out', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
        'last_verified_at' => now()->subMinutes(56),
    ]);
    $post = Post::factory()->scheduled()->create([
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
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($account->fresh()->last_verified_at->isAfter(now()->subMinute()))->toBeTrue();
});

test('records last_verified_at after a successful verification', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
        'last_verified_at' => null,
    ]);
    $post = Post::factory()->scheduled()->create([
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
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($account->fresh()->last_verified_at)->not->toBeNull();
});

test('defers verification until the post is within 30 minutes of publishing', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(55),
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

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    expect($account->fresh()->last_verified_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('verifies once the nearest post in the group crosses the 30-minute lead, even if others are farther out', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $nearPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(25),
    ]);
    $nearPostPlatform = PostPlatform::factory()->create([
        'post_id' => $nearPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $farPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(58),
    ]);
    $farPostPlatform = PostPlatform::factory()->create([
        'post_id' => $farPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($account->fresh()->last_verified_at)->not->toBeNull();
    expect($nearPostPlatform->fresh())->not->toBeNull();
    expect($farPostPlatform->fresh())->not->toBeNull();
});

test('defers to the next run instead of duplicating AccountDisconnected when another process wins the race', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturnUsing(function () use ($account) {
        // Simulate RefreshExpiringTokens/RefreshSocialToken discovering and
        // announcing the same break (its own AccountDisconnected email)
        // between when this job loaded the account as Connected and when
        // its own verify() call returns.
        $account->fresh()->update([
            'status' => SocialAccountStatus::TokenExpired,
            'disconnected_at' => now(),
        ]);

        throw new TokenExpiredException('Threads access token is invalid or expired');
    });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('skips notifying when a concurrent run already claimed the warning window', function () {
    // This simulates the "other run" serially, inside the same process and
    // transaction, by mutating the row from within our own verify() mock —
    // it proves the claim query's WHERE clause excludes an already-claimed
    // row, not that ->lockForUpdate() itself prevents two real overlapping
    // Postgres transactions from double-claiming. That needs two genuinely
    // concurrent connections, which Pest's single-connection RefreshDatabase
    // tests can't exercise.
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturnUsing(function () use ($postPlatform) {
        // Simulate a second, overlapping run of this same job (the
        // ShouldBeUnique lock's TTL matches the schedule cadence, so two
        // instances can briefly overlap if a run takes unusually long)
        // already claiming and warning about this exact post_platform,
        // between this run's select and its own claim update below.
        $postPlatform->update(['connection_warning_sent_at' => now()]);

        throw new TokenExpiredException('Threads access token is invalid or expired');
    });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);
    Mail::assertNothingQueued();
});

test('narrows the notification to only the rows this run actually claimed when a concurrent run claims some but not all', function () {
    // Same caveat as the test above: the "other run" is simulated serially
    // inside our own verify() mock, so this proves the post-claim narrowing
    // logic, not ->lockForUpdate()'s row-locking behavior under two genuinely
    // concurrent Postgres transactions (not reproducible with a single test
    // connection).
    Mail::fake();

    // Both post_platforms belong to the SAME account/group deliberately —
    // unlike a two-account version, this makes the scenario independent of
    // which group a DB result set happens to return first (groupBy()
    // preserves query order, and atRiskPostPlatforms() has no ORDER BY).
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $claimedByOtherRunPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $claimedByOtherRunPostPlatform = PostPlatform::factory()->create([
        'post_id' => $claimedByOtherRunPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $stillClaimableByThisRunPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(25),
    ]);
    $stillClaimableByThisRunPostPlatform = PostPlatform::factory()->create([
        'post_id' => $stillClaimableByThisRunPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->andReturnUsing(function () use ($claimedByOtherRunPostPlatform) {
            // Simulate a second, overlapping run of this same job claiming
            // one of this account's two post_platforms — mid-call, between
            // this run's own read (already reflected in $atRisk) and its
            // claim step below.
            $claimedByOtherRunPostPlatform->update(['connection_warning_sent_at' => now()]);

            throw new TokenExpiredException('Threads access token is invalid or expired');
        });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($stillClaimableByThisRunPostPlatform, $claimedByOtherRunPostPlatform) {
        return count($mail->postPlatformIds) === 1
            && in_array($stillClaimableByThisRunPostPlatform->id, $mail->postPlatformIds, true)
            && ! in_array($claimedByOtherRunPostPlatform->id, $mail->postPlatformIds, true);
    });
});

test('does not crash when the account is deleted between being loaded and the TokenExpiredException being handled', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturnUsing(function () use ($account) {
        // Simulate the user disconnecting (hard-deleting) the account in the
        // brief window between this job loading it and handling the
        // exception thrown here.
        SocialAccount::where('id', $account->id)->delete();

        throw new TokenExpiredException('Threads access token is invalid or expired');
    });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('a deleted account does not abort the run for other accounts in the same workspace', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    $deletedAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $deletedPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(15),
    ]);
    $deletedPostPlatform = PostPlatform::factory()->create([
        'post_id' => $deletedPost->id,
        'social_account_id' => $deletedAccount->id,
        'platform' => $deletedAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $survivingAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $survivingPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $survivingPostPlatform = PostPlatform::factory()->create([
        'post_id' => $survivingPost->id,
        'social_account_id' => $survivingAccount->id,
        'platform' => $survivingAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::on(fn ($account) => $account->id === $deletedAccount->id))
        ->andReturnUsing(function () use ($deletedAccount) {
            SocialAccount::where('id', $deletedAccount->id)->delete();

            throw new TokenExpiredException('Threads access token is invalid or expired');
        });
    $verifier->shouldReceive('verify')
        ->once()
        ->with(Mockery::on(fn ($account) => $account->id === $survivingAccount->id))
        ->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($deletedPostPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    expect($survivingPostPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($survivingAccount->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($survivingPostPlatform) {
        return count($mail->postPlatformIds) === 1
            && in_array($survivingPostPlatform->id, $mail->postPlatformIds, true);
    });
});

test('a post deleted between the main query and the eager-loaded post relation resolving does not crash the run', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $doomedPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(15),
    ]);
    $doomedPostPlatform = PostPlatform::factory()->create([
        'post_id' => $doomedPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $survivingPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $survivingPostPlatform = PostPlatform::factory()->create([
        'post_id' => $survivingPost->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    // atRiskPostPlatforms() runs the post_platforms select, then a separate
    // eager-load query for the post relation. Deleting the post right after
    // the first query — but before the second — reproduces the race: this
    // job's in-memory PostPlatform row still exists, but its post relation
    // resolves to null once the eager load runs.
    // str_starts_with (not str_contains) deliberately excludes the
    // recentlyWarnedAbout()/recentlyDisconnected() exists() subqueries —
    // Laravel compiles ->exists() as "select exists(select * from
    // \"post_platforms\" where ...)", which contains but doesn't start with
    // this prefix, so those never trip the listener.
    $listener = function ($query) use ($doomedPost) {
        if (str_starts_with($query->sql, 'select * from "post_platforms"')) {
            Post::where('id', $doomedPost->id)->delete();
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    // post_platforms.post_id cascades on delete, so the row itself is gone —
    // nothing left to warn about for it.
    expect($doomedPostPlatform->fresh())->toBeNull();
    expect($survivingPostPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($survivingPostPlatform) {
        return count($mail->postPlatformIds) === 1
            && in_array($survivingPostPlatform->id, $mail->postPlatformIds, true);
    });
});

test('does not verify or warn about a post_platform on a paused account', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
        'is_active' => false,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
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

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('still verifies and warns about an active account when another account in the same workspace is paused', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    $pausedAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
        'is_active' => false,
    ]);
    $pausedPost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $pausedPostPlatform = PostPlatform::factory()->create([
        'post_id' => $pausedPost->id,
        'social_account_id' => $pausedAccount->id,
        'platform' => $pausedAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $activeAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $activePost = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(25),
    ]);
    $activePostPlatform = PostPlatform::factory()->create([
        'post_id' => $activePost->id,
        'social_account_id' => $activeAccount->id,
        'platform' => $activeAccount->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    // andReturnUsing (rather than a single ->with()-constrained expectation)
    // deliberately avoids a Mockery expectation-mismatch exception: the job's
    // own catch (Exception $e) around verify() would silently swallow that,
    // masking a missing is_active guard instead of failing the test.
    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->andReturnUsing(function (SocialAccount $account) use ($activeAccount) {
            if ($account->id !== $activeAccount->id) {
                // Only reachable if the is_active guard fails to exclude the paused account.
                return true;
            }

            throw new TokenExpiredException('Threads access token is invalid or expired');
        });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($pausedAccount->fresh()->last_verified_at)->toBeNull();
    expect($pausedPostPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    expect($activePostPlatform->fresh()->connection_warning_sent_at)->not->toBeNull();

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($activePostPlatform, $pausedPostPlatform) {
        return count($mail->postPlatformIds) === 1
            && in_array($activePostPlatform->id, $mail->postPlatformIds, true)
            && ! in_array($pausedPostPlatform->id, $mail->postPlatformIds, true);
    });
});

test('does not verify or warn about an account paused mid-run, after atRiskPostPlatforms() already selected its post_platform', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    // atRiskPostPlatforms()'s is_active guard is query-time only — pause the
    // account right after its main select runs (already passed the guard)
    // but before the per-account loop reaches it, reproducing the race the
    // fresh() re-check at the top of each account's iteration exists to close.
    $listener = function ($query) use ($account) {
        if (str_starts_with($query->sql, 'select * from "post_platforms"')) {
            $account->update(['is_active' => false]);
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('does not warn about an already token_expired account paused mid-run, after atRiskPostPlatforms() already selected its post_platform', function () {
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

    // The "already known broken" branch (no verify() call, straight to
    // $atRisk) must respect the same mid-run pause race as the verify()
    // path — an account paused between the main select and the loop
    // reaching it shouldn't get warned about a connection its owner
    // deliberately paused, even though it's already broken.
    $listener = function ($query) use ($account) {
        if (str_starts_with($query->sql, 'select * from "post_platforms"')) {
            $account->update(['is_active' => false]);
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('does not crash or warn when the account is hard-deleted mid-run, after atRiskPostPlatforms() already selected its post_platform', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    // Same race as the two tests above, but the account is hard-deleted
    // instead of paused — social_account_id is nullOnDelete(), so the
    // post_platform row survives with a dangling reference. The re-fetch
    // guard must handle "row is gone" the same way it handles "row is
    // paused": SocialAccount::active()->find() returns null either way.
    // Deleting after the socialAccount eager-load query (rather than the
    // main post_platforms select, like the two tests above) is deliberate:
    // deleting that early would instead hit the pre-existing, unrelated
    // "$account is null straight out of the eager load" branch — this
    // needs the account to still resolve as non-null going into the loop,
    // then disappear before the guard's own re-fetch runs.
    $listener = function ($query) use ($account) {
        if (str_starts_with($query->sql, 'select * from "social_accounts"')) {
            $account->delete();
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($postPlatform->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
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
        'scheduled_at' => now()->addMinutes(20),
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
        return count($mail->postPlatformIds) === 1
            && in_array($expiredPostPlatform->id, $mail->postPlatformIds, true);
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
        return $mail->workspace->id === $workspaceA->id
            && in_array($postPlatformA->id, $mail->postPlatformIds, true)
            && ! in_array($postPlatformB->id, $mail->postPlatformIds, true);
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
        'scheduled_at' => now()->addMinutes(20),
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
        return count($mail->postPlatformIds) === 1
            && in_array($postPlatform->id, $mail->postPlatformIds, true);
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
