<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\SyncError;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class]);
    $this->workspace = Workspace::factory()->create();
    $this->user = $this->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $this->state = InboxSyncState::factory()->create([
        'social_account_id' => $this->account->id,
        'kind' => ConversationKind::Comment,
        'next_sync_at' => now()->addDay(),
        'last_error' => 'Network said no',
        'error_category' => SyncError::Permission,
        'consecutive_failures' => 5,
    ]);
    Cache::flush();
    Queue::fake([SyncInboxAccount::class]);
});

test('try again clears the kind\'s wait and error and queues its sync', function () {
    $this->actingAs($this->user)
        ->from(route('app.inbox.index'))
        ->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'comment'])
        ->assertRedirect(route('app.inbox.index'));

    $state = $this->state->fresh();
    expect($state->next_sync_at)->toBeNull()
        ->and($state->last_error)->toBeNull()
        ->and($state->syncError())->toBeNull();
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $this->account->id && $job->kind === ConversationKind::Comment && ! $job->backfill);
});

test('the api queues the same sync', function () {
    $api = createApiTestToken();
    $account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $api['workspace']->id]);
    Cache::flush();

    $this->withHeaders(['Authorization' => "Bearer {$api['plain_token']}", 'Accept' => 'application/json'])
        ->postJson(route('api.inbox.channel-sync.store', $account), ['kind' => 'comment'], ['HTTP_HOST' => 'api.trypost.test'])
        ->assertAccepted();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id);
});

test('a kind the channel does not sync is refused', function () {
    $this->actingAs($this->user)
        ->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'review'])
        ->assertSessionHasErrors('kind');

    Queue::assertNothingPushed();
});

test('a channel of another workspace is not found', function () {
    $foreign = SocialAccount::factory()->bluesky()->create();

    $this->actingAs($this->user)->post(route('app.inbox.channel-sync.store', $foreign), ['kind' => 'comment'])->assertNotFound();

    Queue::assertNotPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => ! $job->backfill);
});

test('the route is not found while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);

    $this->actingAs($this->user)->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'comment'])->assertNotFound();
});

test('a member whose posts need approval cannot try again', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'comment'])->assertForbidden();

    Queue::assertNothingPushed();
});

test('trying again is limited per user and channel', function (string $route) {
    expect(Route::getRoutes()->getByName($route)->gatherMiddleware())->toContain('throttle:inbox-sync')
        ->and(RateLimiter::limiter('inbox-sync'))->not->toBeNull();
})->with(['app.inbox.channel-sync.store', 'api.inbox.channel-sync.store']);

test('the seventh try in a minute is refused', function () {
    foreach (range(1, 6) as $attempt) {
        $this->actingAs($this->user)->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'comment'])->assertRedirect();
    }

    $this->actingAs($this->user)->post(route('app.inbox.channel-sync.store', $this->account), ['kind' => 'comment'])->assertTooManyRequests();
});
