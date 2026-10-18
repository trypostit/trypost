<?php

declare(strict_types=1);

use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;
use App\Support\Social\BlueskyTokenScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);

    fakeInboxDriver(new class implements InboxDriver
    {
        public function kinds(): array
        {
            return [ConversationKind::Comment, ConversationKind::DirectMessage];
        }

        public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
        {
            return new PollResult([], $cursor);
        }

        public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
        {
            return new SentReply('id', null, CarbonImmutable::now());
        }

        public function replyLength(SocialAccount $account): int
        {
            return 1000;
        }

        public function replyLengthUnit(): ReplyLengthUnit
        {
            return ReplyLengthUnit::Characters;
        }

        public function prefillsMention(): bool
        {
            return false;
        }
    });
});

test('a Meta channel has DM access only with every DM scope granted', function () {
    $account = SocialAccount::factory()->facebook()->withInboxScopes()->create();

    expect($account->hasInboxAccessFor(ConversationKind::DirectMessage))->toBeFalse()
        ->and(app(InboxDrivers::class)->kindsFor($account))->not->toContain(ConversationKind::DirectMessage);

    $account->update(['scopes' => [...$account->scopes, 'pages_messaging']]);

    expect($account->fresh()->hasInboxAccessFor(ConversationKind::DirectMessage))->toBeTrue()
        ->and(app(InboxDrivers::class)->kindsFor($account->fresh()))->toContain(ConversationKind::DirectMessage);
});

test('a Bluesky app password without DM access', function (string $scope, bool $allowed) {
    $account = SocialAccount::factory()->bluesky()->create(['access_token' => inboxBlueskyJwt($scope)]);

    expect($account->hasInboxAccessFor(ConversationKind::DirectMessage))->toBe($allowed)
        ->and(in_array(ConversationKind::DirectMessage, app(InboxDrivers::class)->kindsFor($account), true))->toBe($allowed);
})->with([
    'app password' => ['com.atproto.appPass', false],
    'app password with DM access' => ['com.atproto.appPassPrivileged', true],
    'account password' => ['com.atproto.access', true],
]);

test('a token that is not a JWT never allows DMs', function () {
    expect(BlueskyTokenScope::allowsDirectMessages('not-a-jwt'))->toBeFalse()
        ->and(BlueskyTokenScope::allowsDirectMessages(null))->toBeFalse();
});

test('Mastodon DMs need the statuses read scope the channel already has', function () {
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['read:accounts', 'read:statuses', 'write:statuses', 'read:notifications']]);

    expect($account->hasInboxAccessFor(ConversationKind::DirectMessage))->toBeTrue();
});

test('the DM sync stops before any request without DM access', function () {
    $account = SocialAccount::factory()->bluesky()->create(['access_token' => inboxBlueskyJwt('com.atproto.appPass')]);
    Http::fake();

    (new SyncInboxAccount($account->id, ConversationKind::DirectMessage))->handle();

    Http::assertNothingSent();
    expect($account->inboxSyncStates()->where('kind', ConversationKind::DirectMessage->value)->exists())->toBeFalse();
});

test('the dispatcher queues a DM sync only for channels with DM access', function () {
    Queue::fake();
    $without = SocialAccount::factory()->bluesky()->create(['access_token' => inboxBlueskyJwt('com.atproto.appPass')]);
    $with = SocialAccount::factory()->bluesky()->create(['access_token' => inboxBlueskyJwt('com.atproto.appPassPrivileged')]);

    $this->artisan('inbox:dispatch-sync')->assertSuccessful();

    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job) => $job->socialAccountId === $with->id && $job->kind === ConversationKind::DirectMessage);
    Queue::assertNotPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job) => $job->socialAccountId === $without->id && $job->kind === ConversationKind::DirectMessage);
    Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job) => $job->socialAccountId === $without->id && $job->kind === ConversationKind::Comment);
});

test('DMs poll on their own interval', function () {
    config()->set('trypost.inbox.poll_minutes.direct_message.bluesky', 5);
    $account = SocialAccount::factory()->bluesky()->create();

    expect(SyncInboxAccount::pollMinutes($account, ConversationKind::DirectMessage))->toBe(5)
        ->and(SyncInboxAccount::pollMinutes($account))->toBe((int) config('trypost.inbox.poll_minutes.bluesky'));
});
