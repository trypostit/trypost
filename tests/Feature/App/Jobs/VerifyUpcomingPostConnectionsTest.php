<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Status as SocialAccountStatus;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Mail\PostAtRisk;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

/**
 * Whether a logged query is a plain select against $table, asking the connection
 * how it quotes identifiers rather than assuming a driver.
 */
function verifyUpcomingSelectsFrom(string $sql, string $table): bool
{
    return str_starts_with($sql, 'select * from '.DB::getQueryGrammar()->wrapTable($table));
}

test('marks the account expired and queues a notification when verify throws TokenExpiredException', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($workspace, $post) {
        return $mail->workspace->id === $workspace->id
            && count($mail->postIds) === 1
            && in_array($post->id, $mail->postIds, true)
            && $mail->recipient->is($workspace->owner);
    });
});

test('emails the workspace owner with the dispatch-time post count', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    Mail::assertQueued(PostAtRisk::class, fn (PostAtRisk $mail) => $mail->count === 1
        && $mail->hasTo($workspace->owner->email));
});

test('defers to the next run instead of warning when markAsTokenExpired loses the account status lock', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
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
        expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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

    $posts = collect(range(1, 3))->map(fn (int $i) => Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20 * $i),
    ]));

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    foreach ($posts as $post) {
        expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
    }

    Mail::assertQueued(PostAtRisk::class, fn ($mail) => count($mail->postIds) === 3);
});

test('ignores posts outside the 1-hour window', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addHours(3),
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
    $post = Post::factory()->forAccount($account)->draft()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
    Mail::assertQueued(PostAtRisk::class);
});

test('does not re-verify an account already known disconnected, but still warns about new posts', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Disconnected,
        'error_message' => 'Threads account was disconnected',
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
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
    $earlierPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(10),
        'connection_warning_sent_at' => now()->subMinutes(10),
    ]);

    // A brand-new post that just entered the risk window — never warned.
    $newPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    // Left unstamped so it's picked up once the cooldown expires, instead of
    // being silently absorbed without ever being mentioned in an email.
    expect($newPost->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('re-notifies about an already-broken account once the cooldown has passed', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::TokenExpired,
    ]);

    $earlierPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(10),
        'connection_warning_sent_at' => now()->subMinutes(90),
    ]);

    $newPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($newPost->fresh()->connection_warning_sent_at)->not->toBeNull();
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(55),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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

    $nearPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(25),
    ]);

    $farPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(58),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($account->fresh()->last_verified_at)->not->toBeNull();
    expect($nearPost->fresh())->not->toBeNull();
    expect($farPost->fresh())->not->toBeNull();
});

test('defers to the next run instead of duplicating AccountDisconnected when another process wins the race', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
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

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturnUsing(function () use ($post) {
        // Simulate a second, overlapping run of this same job (the
        // ShouldBeUnique lock's TTL matches the schedule cadence, so two
        // instances can briefly overlap if a run takes unusually long)
        // already claiming and warning about this exact post,
        // between this run's select and its own claim update below.
        $post->update(['connection_warning_sent_at' => now()]);

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

    // Both posts belong to the SAME account/group deliberately —
    // unlike a two-account version, this makes the scenario independent of
    // which group a DB result set happens to return first (groupBy()
    // preserves query order, and atRiskPosts() has no ORDER BY).
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $claimedByOtherRunPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $stillClaimableByThisRunPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(25),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->andReturnUsing(function () use ($claimedByOtherRunPost) {
            // Simulate a second, overlapping run of this same job claiming
            // one of this account's two posts — mid-call, between
            // this run's own read (already reflected in $atRisk) and its
            // claim step below.
            $claimedByOtherRunPost->update(['connection_warning_sent_at' => now()]);

            throw new TokenExpiredException('Threads access token is invalid or expired');
        });
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($stillClaimableByThisRunPost, $claimedByOtherRunPost) {
        return count($mail->postIds) === 1
            && in_array($stillClaimableByThisRunPost->id, $mail->postIds, true)
            && ! in_array($claimedByOtherRunPost->id, $mail->postIds, true);
    });
});

test('does not crash when the account is deleted between being loaded and the TokenExpiredException being handled', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
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

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('a deleted account does not abort the run for other accounts in the same workspace', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    $deletedAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $deletedPost = Post::factory()->forAccount($deletedAccount)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(15),
    ]);

    $survivingAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $survivingPost = Post::factory()->forAccount($survivingAccount)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
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

    expect($deletedPost->fresh()->connection_warning_sent_at)->toBeNull();
    expect($survivingPost->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($survivingAccount->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($survivingPost) {
        return count($mail->postIds) === 1
            && in_array($survivingPost->id, $mail->postIds, true);
    });
});

test('a post deleted right after the main query does not crash the run', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);

    $doomedPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(15),
    ]);

    $survivingPost = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $listener = function ($query) use ($doomedPost) {
        if (verifyUpcomingSelectsFrom($query->sql, 'posts')) {
            Post::where('id', $doomedPost->id)->delete();
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($doomedPost->fresh())->toBeNull();
    expect($survivingPost->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($survivingPost) {
        return count($mail->postIds) === 1
            && in_array($survivingPost->id, $mail->postIds, true);
    });
});

test('does not crash or warn when the account is hard-deleted mid-run, after atRiskPosts() already selected its post', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $listener = function ($query) use ($account) {
        if (verifyUpcomingSelectsFrom($query->sql, 'social_accounts')) {
            $account->delete();
        }
    };
    DB::listen($listener);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('does not warn and does not mark connection_warning_sent_at on PlatformUnavailableException', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new PlatformUnavailableException('Threads API returned 503'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andReturn(true);
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
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
    $brokenPost = Post::factory()->forAccount($brokenAccount)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $expiredAccount = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $expiredPost = Post::factory()->forAccount($expiredAccount)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
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

    expect($brokenPost->fresh()->connection_warning_sent_at)->toBeNull();
    expect($brokenAccount->fresh()->status)->toBe(SocialAccountStatus::Connected);

    expect($expiredPost->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($expiredAccount->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($expiredPost) {
        return count($mail->postIds) === 1
            && in_array($expiredPost->id, $mail->postIds, true);
    });
});

test('does not leak another workspace\'s at-risk posts into this workspace\'s notification', function () {
    Mail::fake();

    $workspaceA = Workspace::factory()->create();
    $accountA = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspaceA->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $postA = Post::factory()->forAccount($accountA)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $workspaceB = Workspace::factory()->create();
    $accountB = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspaceB->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $postB = Post::factory()->forAccount($accountB)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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

    expect($postA->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($postB->fresh()->connection_warning_sent_at)->toBeNull();
    expect($accountB->fresh()->status)->toBe(SocialAccountStatus::Connected);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($workspaceA, $postA, $postB) {
        return $mail->workspace->id === $workspaceA->id
            && in_array($postA->id, $mail->postIds, true)
            && ! in_array($postB->id, $mail->postIds, true);
    });

    Mail::assertQueuedCount(1);
});

test('re-evaluates a post warned more than a day ago instead of skipping it forever', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
        'connection_warning_sent_at' => now()->subDay()->subMinute(),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull()
        ->and($post->fresh()->connection_warning_sent_at->isAfter(now()->subMinute()))->toBeTrue();

    Mail::assertQueued(PostAtRisk::class);
});

test('still skips a post warned less than a day ago', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $warnedAt = now()->subHours(2);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
        'connection_warning_sent_at' => $warnedAt,
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldNotReceive('verify');
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at->format('Y-m-d H:i:s'))
        ->toBe($warnedAt->format('Y-m-d H:i:s'));
    Mail::assertNothingQueued();
});

test('does not re-warn a re-armed post when the account has since been reconnected', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $warnedAt = now()->subDay()->subMinute();
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
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
    expect($post->fresh()->connection_warning_sent_at->format('Y-m-d H:i:s'))
        ->toBe($warnedAt->format('Y-m-d H:i:s'));
    expect($account->fresh()->status)->toBe(SocialAccountStatus::Connected);
    Mail::assertNothingQueued();
});

test('does not crash the run on a scheduled post without a channel', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create();

    Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(20),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Facebook access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->not->toBeNull();
    expect($account->fresh()->status)->toBe(SocialAccountStatus::TokenExpired);

    Mail::assertQueued(PostAtRisk::class, function ($mail) use ($post) {
        return count($mail->postIds) === 1
            && in_array($post->id, $mail->postIds, true);
    });
});

test('leaves posts unwarned and does not send an email when the workspace has no owner', function () {
    Mail::fake();

    $workspace = Workspace::factory()->create(['user_id' => null]);
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'status' => SocialAccountStatus::Connected,
    ]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $verifier = mock(ConnectionVerifier::class);
    $verifier->shouldReceive('verify')->once()->andThrow(new TokenExpiredException('Threads access token is invalid or expired'));
    app()->instance(ConnectionVerifier::class, $verifier);

    VerifyUpcomingPostConnections::dispatchSync($workspace->id);

    expect($post->fresh()->connection_warning_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

test('job is unique per workspace with a window covering its timeout', function () {
    $job = new VerifyUpcomingPostConnections('workspace-uuid');

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('workspace-uuid')
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual($job->timeout);
});
