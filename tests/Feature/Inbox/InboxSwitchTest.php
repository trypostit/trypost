<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

dataset('inbox channels', [
    'instagram' => [fn () => SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(), [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]],
    'instagram via facebook' => [fn () => SocialAccount::factory()->instagramFacebook()->withDirectMessageScopes()->create(), [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]],
    'facebook' => [fn () => SocialAccount::factory()->facebook()->withDirectMessageScopes()->create(), [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]],
    'threads' => [fn () => SocialAccount::factory()->threads()->create(['scopes' => ['threads_basic', 'threads_read_replies', 'threads_manage_replies', 'threads_manage_mentions']]), [ConversationKind::Comment, ConversationKind::Mention]],
    'bluesky' => [fn () => SocialAccount::factory()->bluesky()->create(['access_token' => inboxBlueskyJwt('com.atproto.appPassPrivileged')]), [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]],
    'mastodon' => [fn () => SocialAccount::factory()->mastodon()->withDirectMessageScopes()->create(), [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]],
]);

test('with the inbox on, a channel that granted the scopes syncs its mentions and messages', function (Closure $channel, array $kinds) {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);

    expect(app(InboxDrivers::class)->kindsFor($channel()))->toBe($kinds);
})->with('inbox channels');

test('with the inbox on, the dispatcher queues every kind the channel granted', function (Closure $channel, array $kinds) {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $account = $channel();

    Artisan::call('inbox:dispatch-sync');

    foreach ($kinds as $kind) {
        Queue::assertPushed(SyncInboxAccount::class, fn (SyncInboxAccount $job): bool => $job->socialAccountId === $account->id && $job->kind === $kind);
    }
})->with('inbox channels');

test('with the inbox off, nothing syncs', function (Closure $channel) {
    Queue::fake();
    $channel();

    Artisan::call('inbox:dispatch-sync');

    Queue::assertNotPushed(SyncInboxAccount::class);
})->with('inbox channels');

test('a threads channel connected before mentions were asked for keeps its comments and shows reconnect for mentions', function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $account = SocialAccount::factory()->threads()->withInboxScopes()->create();

    expect($account->hasInboxAccess())->toBeTrue()
        ->and($account->hasInboxMentionAccess())->toBeFalse()
        ->and(app(InboxDrivers::class)->kindsFor($account))->toBe([ConversationKind::Comment]);
});
