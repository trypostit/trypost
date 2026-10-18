<?php

declare(strict_types=1);

use App\Dto\Inbox\PollResult;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Jobs\Inbox\HandleMetaWebhook;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->graph = config('trypost.platforms.facebook.graph_api');
});

function inboxReadyPage(): SocialAccount
{
    return SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
}

test('connecting an inbox-ready channel backfills by polling first and subscribes once the backfill completes', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);

    $account = inboxReadyPage();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id && $job->backfill);
    Queue::assertNotPushed(SubscribeInboxWebhooks::class);

    $this->mock(FacebookInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult([], 'done'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment, true))->handle();

    Queue::assertPushedOn('inbox', SubscribeInboxWebhooks::class, fn (SubscribeInboxWebhooks $job): bool => $job->socialAccountId === $account->id);
});

test('a backfill that stopped at its budget does not subscribe yet', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    $this->mock(FacebookInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->andReturn(new PollResult([], 'resume', partial: true));

    (new SyncInboxAccount($account->id, ConversationKind::Comment, true))->handle();

    Queue::assertNotPushed(SubscribeInboxWebhooks::class);
});

test('the dispatcher does not subscribe a channel whose backfill is unfinished', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    InboxSyncState::query()->where('social_account_id', $account->id)->delete();
    InboxSyncState::query()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment, 'next_sync_at' => now()->subMinute()]);

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->backfill);
    Queue::assertNotPushed(SubscribeInboxWebhooks::class);
});

test('a channel without inbox scopes queues no subscription', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);

    SocialAccount::factory()->facebook()->create();

    Queue::assertNotPushed(SubscribeInboxWebhooks::class);
});

test('bluesky, which has no webhooks, queues no subscription', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);

    SocialAccount::factory()->bluesky()->create();

    Queue::assertPushed(SyncInboxAccount::class);
    Queue::assertNotPushed(SubscribeInboxWebhooks::class);
});

test('a successful subscription stamps the sync state', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['success' => true])]);

    (new SubscribeInboxWebhooks($account->id))->handle();

    $state = InboxSyncState::query()->where('social_account_id', $account->id)->where('kind', ConversationKind::Comment)->sole();

    expect($state->webhook_subscribed_at)->not->toBeNull()
        ->and($state->last_error)->toBeNull();
});

test('an expired token marks the channel', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 400)]);

    (new SubscribeInboxWebhooks($account->id))->handle();

    expect($account->fresh()->status)->toBe(Status::TokenExpired);
});

test('a refused subscription records the error and leaves the stamp empty', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['success' => false])]);

    (new SubscribeInboxWebhooks($account->id))->handle();

    $state = InboxSyncState::query()->where('social_account_id', $account->id)->sole();

    expect($state->webhook_subscribed_at)->toBeNull()
        ->and($state->last_error)->not->toBeNull();
});

test('a meta outage is left to the job retries', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    Http::fake(["{$this->graph}/page-1/subscribed_apps*" => Http::response(['error' => ['code' => 2]], 500)]);

    (new SubscribeInboxWebhooks($account->id))->handle();
})->throws(PlatformUnavailableException::class);

test('a channel that lost inbox access is not subscribed', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    $account->forceFill(['scopes' => ['pages_manage_posts']])->save();
    Http::preventStrayRequests();

    (new SubscribeInboxWebhooks($account->id))->handle();

    expect(InboxSyncState::query()->where('social_account_id', $account->id)->exists())->toBeFalse();
});

test('the sync dispatcher re-subscribes a channel that never subscribed', function (bool $subscribed) {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = inboxReadyPage();
    InboxSyncState::query()->create([
        'social_account_id' => $account->id,
        'kind' => ConversationKind::Comment,
        'next_sync_at' => now()->subMinute(),
        'backfilled_at' => now()->subDay(),
        'webhook_subscribed_at' => $subscribed ? now()->subDay() : null,
    ]);
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class);
    $subscribed
        ? Queue::assertNotPushed(SubscribeInboxWebhooks::class)
        : Queue::assertPushed(SubscribeInboxWebhooks::class, fn (SubscribeInboxWebhooks $job): bool => $job->socialAccountId === $account->id);
})->with([
    'never subscribed' => [false],
    'already subscribed' => [true],
]);

test('meta channels without recent webhooks poll comments every 15 minutes and direct messages every 5', function (string $state) {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = SocialAccount::factory()->{$state}()->withInboxScopes()->create();
    InboxSyncState::query()->where('social_account_id', $account->id)->delete();

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    $nextSync = InboxSyncState::query()->where('social_account_id', $account->id)->where('kind', ConversationKind::Comment)->sole()->next_sync_at;

    expect(SyncInboxAccount::pollMinutes($account))->toBe(15)
        ->and(SyncInboxAccount::pollMinutes($account, ConversationKind::DirectMessage))->toBe($state === 'threads' ? 15 : 5)
        ->and(abs($nextSync->diffInMinutes(now()->addMinutes(15))))->toBeLessThan(2);
})->with(['instagram', 'instagramFacebook', 'facebook', 'threads']);

test('a meta kind that received a webhook in the last day only reconciles every six hours', function (string $state) {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $recent = SocialAccount::factory()->{$state}()->withInboxScopes()->create();
    $stale = SocialAccount::factory()->{$state}()->withInboxScopes()->create();
    InboxSyncState::query()->delete();
    $recentState = InboxSyncState::query()->create(['social_account_id' => $recent->id, 'kind' => ConversationKind::Comment, 'backfilled_at' => now(), 'last_webhook_at' => now()->subHours(23)]);
    $staleState = InboxSyncState::query()->create(['social_account_id' => $stale->id, 'kind' => ConversationKind::Comment, 'backfilled_at' => now(), 'last_webhook_at' => now()->subHours(25)]);

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    expect(SyncInboxAccount::pollMinutes($recent, ConversationKind::Comment, $recentState))->toBe(360)
        ->and(SyncInboxAccount::pollMinutes($stale, ConversationKind::Comment, $staleState))->toBe(15)
        ->and(SyncInboxAccount::pollMinutes($recent, ConversationKind::DirectMessage, new InboxSyncState))->toBe($state === 'threads' ? 15 : 5)
        ->and(abs($recentState->fresh()->next_sync_at->diffInMinutes(now()->addMinutes(360))))->toBeLessThan(2)
        ->and(abs($staleState->fresh()->next_sync_at->diffInMinutes(now()->addMinutes(15))))->toBeLessThan(2);
})->with(['instagram', 'instagramFacebook', 'facebook', 'threads']);

test('networks without webhooks keep their cadence whatever the state says', function () {
    $youtube = SocialAccount::factory()->youtube()->create();
    $bluesky = SocialAccount::factory()->bluesky()->create();
    $recent = new InboxSyncState(['last_webhook_at' => now()]);

    expect(SyncInboxAccount::pollMinutes($youtube, ConversationKind::Comment, $recent))->toBe((int) config('trypost.inbox.poll_minutes.youtube'))
        ->and(SyncInboxAccount::pollMinutes($bluesky, ConversationKind::Comment, $recent))->toBe(15)
        ->and(SyncInboxAccount::pollMinutes($bluesky, ConversationKind::DirectMessage, $recent))->toBe(5);
});

test('a webhook delivery that brings messages marks their kind as receiving webhooks', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    config()->set('services.facebook.client_secret', 'fb-secret');
    $account = inboxReadyPage();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'page-1_post-1']);

    HandleMetaWebhook::dispatchSync(json_decode((string) file_get_contents(base_path('tests/fixtures/inbox/facebook-feed-comment.json')), true));

    $state = InboxSyncState::query()->where('social_account_id', $account->id)->where('kind', ConversationKind::Comment)->sole();

    expect($state->last_webhook_at)->not->toBeNull()
        ->and($state->receivesWebhooks())->toBeTrue()
        ->and(InboxSyncState::query()->where('social_account_id', $account->id)->whereNotNull('last_webhook_at')->count())->toBe(1);
});

test('a reconnect backfills again, and the subscription follows that backfill', function () {
    Queue::fake([SubscribeInboxWebhooks::class, SyncInboxAccount::class]);
    $account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['status' => Status::TokenExpired]);

    Queue::assertNotPushed(SyncInboxAccount::class);

    $account->update(['status' => Status::Connected]);

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id && $job->backfill);
    Queue::assertNotPushed(SubscribeInboxWebhooks::class);
});
