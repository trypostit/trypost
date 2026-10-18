<?php

declare(strict_types=1);

use App\Dto\Inbox\PollResult;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\SyncError;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\BlueskyPublishException;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    Exceptions::fake();
    $this->graph = config('trypost.platforms.facebook.graph_api');
});

function facebookInboxChannel(): SocialAccount
{
    return SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
}

function failCommentSync(SocialAccount $account, array $error, int $status = 403): InboxSyncState
{
    Http::fake(['*' => Http::response(['error' => $error], $status)]);

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    return InboxSyncState::query()->where('social_account_id', $account->id)->where('kind', ConversationKind::Comment)->sole();
}

test('a Meta permission error is a permission sync error, read by its code', function (array $error) {
    $state = failCommentSync(facebookInboxChannel(), $error);

    expect($state->error_category)->toBe(SyncError::Permission)
        ->and($state->syncError())->toBe(SyncError::Permission)
        ->and($state->consecutive_failures)->toBe(1);
})->with([
    'api permission denied' => [['message' => '(#10) Requires pages_read_user_content', 'type' => 'OAuthException', 'code' => 10]],
    'api permission range' => [['message' => '(#200) Missing permission', 'type' => 'OAuthException', 'code' => 200]],
    'end of the range' => [['message' => '(#299) Missing permission', 'type' => 'OAuthException', 'code' => 299]],
]);

test('a Meta permission error while reading comments is read by its code', function () {
    $account = facebookInboxChannel();
    Http::fake([
        "{$this->graph}/page-1/published_posts*" => Http::response(['data' => [['id' => 'page-1_post-1', 'created_time' => now()->subDay()->toIso8601String()]]]),
        "{$this->graph}/page-1_post-1/comments*" => Http::response(['error' => ['message' => '(#10) Requires pages_read_user_content', 'type' => 'OAuthException', 'code' => 10]], 400),
    ]);

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->where('social_account_id', $account->id)->sole()->syncError())->toBe(SyncError::Permission);
});

test('an error whose text speaks of permissions is not a permission error without the code', function () {
    $state = failCommentSync(facebookInboxChannel(), ['message' => 'Missing permission to read this object', 'type' => 'GraphMethodException', 'code' => 100], 400);

    expect($state->error_category)->toBeNull()
        ->and($state->syncError())->toBeNull();
});

test('a permission error on a network outside Meta is not read as one', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andThrow(new BlueskyPublishException(
            userMessage: 'no', category: ErrorCategory::Unknown, rawResponse: json_encode(['error' => ['code' => 10]]),
        ));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->sole()->syncError())->toBeNull();
});

test('three failures in a row read as failing and a good sync clears every error', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $driver = $this->mock(BlueskyInboxDriver::class);
    $driver->shouldReceive('kinds')->andReturn([ConversationKind::Comment]);
    $driver->shouldReceive('poll')->times(3)->andThrow(new PlatformUnavailableException('down'));

    foreach (range(1, 3) as $attempt) {
        (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

        expect(InboxSyncState::query()->sole()->syncError())->toBe($attempt < 3 ? null : SyncError::Failing);
    }

    $driver->shouldReceive('poll')->once()->andReturn(new PollResult([], 'c'));
    InboxSyncState::query()->sole()->forceFill(['error_category' => SyncError::Permission])->save();

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    $state = InboxSyncState::query()->sole();
    expect($state->syncError())->toBeNull()
        ->and($state->error_category)->toBeNull()
        ->and($state->last_synced_at)->not->toBeNull();
});

test('messages turned off by the account owner read as messages unavailable', function () {
    Http::fake([config('trypost.platforms.instagram-facebook.graph_api').'/*' => Http::response(['error' => [
        'message' => '(#200) The account owner has disabled access to instagram direct messages.',
        'type' => 'OAuthException', 'code' => 200, 'error_subcode' => 2534041,
    ]], 403)]);
    $account = SocialAccount::factory()->instagramFacebook()->withDirectMessageScopes()->create(['token_expires_at' => null]);

    (new SyncInboxAccount($account->id, ConversationKind::DirectMessage))->handle();

    expect(InboxSyncState::query()->where('social_account_id', $account->id)->sole()->syncError())->toBe(SyncError::MessagesUnavailable);
});

test('the inbox page gives every channel the sync state of each kind it syncs', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->facebook()->withDirectMessageScopes()->create(['workspace_id' => $workspace->id, 'token_expires_at' => null]);
    InboxSyncState::query()->updateOrCreate(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment], ['consecutive_failures' => 3, 'last_synced_at' => '2026-10-16 10:00:00']);
    InboxSyncState::query()->updateOrCreate(['social_account_id' => $account->id, 'kind' => ConversationKind::DirectMessage], ['error_category' => SyncError::MessagesUnavailable, 'consecutive_failures' => 0, 'last_synced_at' => null]);
    InboxSyncState::query()->updateOrCreate(['social_account_id' => $account->id, 'kind' => ConversationKind::Mention], ['error_category' => SyncError::Permission, 'consecutive_failures' => 1, 'last_synced_at' => '2026-10-16 09:00:00']);

    $this->actingAs($user->fresh())->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inboxChannels.0.id', $account->id)
            ->where('inboxChannels.0.sync_states', [
                ['kind' => 'comment', 'sync_error' => 'failing', 'last_synced_at' => '2026-10-16T10:00:00+00:00'],
                ['kind' => 'mention', 'sync_error' => 'permission', 'last_synced_at' => '2026-10-16T09:00:00+00:00'],
                ['kind' => 'direct_message', 'sync_error' => 'messages_unavailable', 'last_synced_at' => null],
            ]));
});
