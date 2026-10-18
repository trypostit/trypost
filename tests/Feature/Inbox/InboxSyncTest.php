<?php

declare(strict_types=1);

use App\Console\Commands\Inbox\DispatchInboxSync;
use App\Dto\Inbox\PollResult;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Status;
use App\Events\InboxUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Support\Analytics\AnalyticsRateLimits;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
});

test('connecting an enabled channel queues its backfill', function () {
    Queue::fake([SyncInboxAccount::class]);

    $account = SocialAccount::factory()->bluesky()->create();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id && $job->backfill);
});

test('the inbox off queues nothing', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);

    SocialAccount::factory()->bluesky()->create();

    Queue::assertNotPushed(SyncInboxAccount::class);
});

test('a sync stores the messages and the cursor', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult(
            [inboxIncoming('at://fan/c1')],
            '2026-10-16T12:00:00Z',
        ));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    $state = InboxSyncState::query()->sole();
    expect(InboxMessage::query()->count())->toBe(1)
        ->and($state->cursor)->toBe('2026-10-16T12:00:00Z')
        ->and($state->next_sync_at->isFuture())->toBeTrue();
});

test('a sync that stopped at the network limit keeps what it read and comes back when the limit lifts', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    $retryAt = now()->addMinutes(4)->startOfSecond();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult([inboxIncoming('at://fan/c1')], 'partial', $retryAt));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    $state = InboxSyncState::query()->sole();
    expect(InboxMessage::query()->count())->toBe(1)
        ->and($state->cursor)->toBe('partial')
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->next_sync_at->getTimestamp())->toBe($retryAt->getTimestamp());
});

test('an expired token marks the channel and stops', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andThrow(new TokenExpiredException('expired'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect($account->fresh()->status)->toBe(Status::TokenExpired);
});

test('an unavailable platform backs the channel off', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andThrow(new PlatformUnavailableException('down'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    $state = InboxSyncState::query()->sole();
    expect($state->consecutive_failures)->toBe(1)
        ->and($state->last_error)->toBe('down')
        ->and($state->next_sync_at->isFuture())->toBeTrue();
});

test('the dispatcher queues only due enabled channels', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $due = SocialAccount::factory()->bluesky()->create();
    $later = SocialAccount::factory()->bluesky()->create();
    InboxSyncState::factory()->create(['social_account_id' => $due->id, 'next_sync_at' => now()->subMinute(), 'backfilled_at' => now()->subHour()]);
    InboxSyncState::factory()->create(['social_account_id' => $later->id, 'next_sync_at' => now()->addMinutes(10), 'backfilled_at' => now()->subHour()]);

    config()->set('trypost.inbox.enabled', true);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => ! $job->backfill && $job->socialAccountId === $due->id);
    Queue::assertNotPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => ! $job->backfill && $job->socialAccountId === $later->id);
    expect(InboxSyncState::query()->where('social_account_id', $due->id)->where('kind', ConversationKind::Comment)->sole()->next_sync_at->isFuture())->toBeTrue();
});

test('the dispatcher backfills a channel that never finished its backfill', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $pending = SocialAccount::factory()->bluesky()->create();
    $done = SocialAccount::factory()->bluesky()->create();
    InboxSyncState::factory()->create(['social_account_id' => $pending->id, 'next_sync_at' => now()->subMinute(), 'backfilled_at' => null]);
    InboxSyncState::factory()->create(['social_account_id' => $done->id, 'next_sync_at' => now()->subMinute(), 'backfilled_at' => now()->subHour()]);
    config()->set('trypost.inbox.enabled', true);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->backfill && $job->socialAccountId === $pending->id);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => ! $job->backfill && $job->socialAccountId === $done->id);
});

test('reconnecting a channel queues exactly one backfill per kind', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create(['status' => Status::TokenExpired]);
    config()->set('trypost.inbox.enabled', true);

    $account->update(['status' => Status::Connected]);

    Queue::assertPushed(SyncInboxAccount::class, 2);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->backfill && $job->socialAccountId === $account->id && $job->kind === ConversationKind::Comment);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->backfill && $job->socialAccountId === $account->id && $job->kind === ConversationKind::Mention);
});

test('disconnecting a channel deletes its conversations', function () {
    Queue::fake([SyncInboxAccount::class]);
    $conversation = InboxConversation::factory()->create();
    $user = $conversation->workspace->owner;
    $user->update(['current_workspace_id' => $conversation->workspace_id]);

    $this->actingAs($user)->delete(route('app.channels.disconnect', $conversation->socialAccount));

    expect(InboxConversation::query()->count())->toBe(0);
});

test('pruning removes messages past the retention', function () {
    $old = InboxMessage::factory()->create(['platform_created_at' => now()->subMonths(13)]);
    $kept = InboxMessage::factory()->create(['platform_created_at' => now()->subMonth()]);

    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(InboxConversation::query()->whereKey($old->inbox_conversation_id)->exists())->toBeFalse();
});

test('pruning keeps a thread whose newest reply is still within the retention', function () {
    $root = InboxMessage::factory()->create(['platform_created_at' => now()->subMonths(14)]);
    $child = InboxMessage::factory()->for($root->conversation, 'conversation')->create(['parent_id' => $root->id, 'root_id' => $root->id, 'platform_created_at' => now()->subMonths(13)]);
    $recent = InboxMessage::factory()->for($root->conversation, 'conversation')->create(['parent_id' => $child->id, 'root_id' => $root->id, 'platform_created_at' => now()->subMonth()]);

    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->pluck('id')->sort()->values()->all())->toBe(collect([$root->id, $child->id, $recent->id])->sort()->values()->all())
        ->and($recent->fresh()->root_id)->toBe($root->id);
});

test('pruning deletes a whole old thread, grandchildren included', function () {
    $root = InboxMessage::factory()->create(['platform_created_at' => now()->subMonths(15)]);
    $child = InboxMessage::factory()->for($root->conversation, 'conversation')->create(['parent_id' => $root->id, 'root_id' => $root->id, 'platform_created_at' => now()->subMonths(14)]);
    InboxMessage::factory()->for($root->conversation, 'conversation')->create(['parent_id' => $child->id, 'root_id' => $root->id, 'platform_created_at' => now()->subMonths(13)]);

    $this->artisan('inbox:prune')->assertSuccessful();

    expect(InboxMessage::query()->count())->toBe(0);
});

test('a sync broadcasts once per touched conversation after the batch', function () {
    Queue::fake([SyncInboxAccount::class]);
    Event::fake([InboxUpdated::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->twice()->andReturn(new PollResult(
            [inboxIncoming('at://fan/c1'), inboxIncoming('at://fan/c2', 'at://fan/c1', at: '2026-10-16 11:00:00')],
            '2026-10-16T12:00:00Z',
        ));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    Event::assertDispatchedTimes(InboxUpdated::class, 1);

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    Event::assertDispatchedTimes(InboxUpdated::class, 1);
});

test('a sync polls the kind it was queued for', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->withArgs(fn (SocialAccount $polled, ConversationKind $kind): bool => $kind === ConversationKind::Comment)
        ->andReturn(new PollResult([], null));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->sole()->kind)->toBe(ConversationKind::Comment);
});

test('a kind the driver does not read is skipped', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->never();

    (new SyncInboxAccount($account->id, ConversationKind::Review))->handle();

    expect(InboxSyncState::query()->count())->toBe(0);
});

test('one channel queues one sync per kind with its own state', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    config()->set('trypost.inbox.enabled', true);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment, ConversationKind::Mention]);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, 2);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->kind === ConversationKind::Mention && $job->backfill);
    expect(InboxSyncState::query()->where('social_account_id', $account->id)->pluck('kind')->all())
        ->toEqualCanonicalizing([ConversationKind::Comment, ConversationKind::Mention])
        ->and((new SyncInboxAccount($account->id, ConversationKind::Comment))->uniqueId())
        ->not->toBe((new SyncInboxAccount($account->id, ConversationKind::Mention))->uniqueId());
});

test('the dispatcher skips workspaces without app access', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    config()->set('trypost.self_hosted', false);
    $account->workspace->account->subscriptions()->delete();
    config()->set('trypost.inbox.enabled', true);

    expect($account->workspace->account->fresh()->hasAppAccess())->toBeFalse();

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertNotPushed(SyncInboxAccount::class);
});

test('a cursor longer than 512 characters is stored and read back', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $cursor = json_encode(['notifications' => '1', 'statuses' => '2', 'backlog' => array_map(fn (int $i): string => (string) (113000000000000000 + $i), range(1, 400))]);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult([], $cursor));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(strlen($cursor))->toBeGreaterThan(512)
        ->and(InboxSyncState::query()->sole()->fresh()->cursor)->toBe($cursor)
        ->and(InboxSyncState::query()->sole()->last_error)->toBeNull();
});

test('rolling the cursor column back first clears the cursors that would no longer fit', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $long = InboxSyncState::query()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment, 'cursor' => str_repeat('9', 600)]);
    $short = InboxSyncState::query()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Mention, 'cursor' => 'n1']);
    $migration = require database_path('migrations/2026_10_17_031107_widen_inbox_sync_states_cursor.php');

    $migration->clearCursorsLongerThan(512);

    expect($long->fresh()->cursor)->toBeNull()
        ->and($short->fresh()->cursor)->toBe('n1');
});

test('an inbox sync spends the same per-host counter as the analytics jobs', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult([], null));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(RateLimiter::attempts(AnalyticsRateLimits::sharedHostGuard($account)['key']))->toBe(1);
});

test('an inbox sync waits while the host is at its shared limit', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    $guard = AnalyticsRateLimits::sharedHostGuard($account);
    foreach (range(1, $guard['limit']->maxAttempts) as $attempt) {
        RateLimiter::hit($guard['key'], $guard['limit']->decaySeconds);
    }
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->never();

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->sole()->next_sync_at->isFuture())->toBeTrue();
});

test('the dispatcher puts off a channel without inbox access instead of reloading it every minute', function () {
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['write:statuses']]);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertNotPushed(SyncInboxAccount::class);
    expect(InboxSyncState::query()->where('social_account_id', $account->id)->get())->not->toBeEmpty()
        ->each(fn ($state) => $state->next_sync_at->isAfter(now()->addHours(23))->toBeTrue());
});

test('the dispatcher puts off a workspace without app access for an hour', function () {
    config()->set('trypost.inbox.enabled', false);
    Queue::fake([SyncInboxAccount::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    config()->set('trypost.self_hosted', false);
    $account->workspace->account->subscriptions()->delete();
    config()->set('trypost.inbox.enabled', true);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    $state = InboxSyncState::query()->where('social_account_id', $account->id)->where('kind', ConversationKind::Comment)->sole();
    expect($state->next_sync_at->isFuture())->toBeTrue()
        ->and($state->next_sync_at->isBefore(now()->addHours(2)))->toBeTrue();
});

test('a due state of a kind its driver does not read is not loaded and its wait clears on reconnect', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    Queue::fake([SyncInboxAccount::class]);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment]);
    InboxSyncState::query()->where('social_account_id', $account->id)->delete();
    InboxSyncState::factory()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment, 'next_sync_at' => now()->addHour(), 'backfilled_at' => now()]);
    $mention = InboxSyncState::factory()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Mention, 'next_sync_at' => now()->subMinute(), 'backfilled_at' => now()]);

    $this->artisan(DispatchInboxSync::class)->assertSuccessful();

    Queue::assertNotPushed(SyncInboxAccount::class);

    $account->update(['status' => Status::TokenExpired]);
    $account->update(['status' => Status::Connected]);

    expect($mention->refresh()->next_sync_at)->toBeNull();
});
