<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:me',
        'username' => 'me.bsky.social',
    ]);
    $this->service = config('trypost.platforms.bluesky.default_service');
    $this->appview = config('trypost.platforms.bluesky.public_appview');
});

function blueskyReplyNotification(string $rkey, string $parentUri, string $indexedAt, string $author = 'did:plc:fan'): array
{
    return [
        'uri' => "at://{$author}/app.bsky.feed.post/{$rkey}",
        'cid' => "cid-{$rkey}",
        'reason' => 'reply',
        'indexedAt' => $indexedAt,
        'author' => ['did' => $author, 'handle' => 'fan.bsky.social', 'displayName' => 'Fan', 'avatar' => 'https://cdn/fan.jpg'],
        'record' => [
            'text' => "Reply {$rkey}",
            'createdAt' => $indexedAt,
            'reply' => [
                'root' => ['uri' => 'at://did:plc:me/app.bsky.feed.post/root1', 'cid' => 'cid-root1'],
                'parent' => ['uri' => $parentUri, 'cid' => 'cid-parent'],
            ],
        ],
    ];
}

test('poll returns replies on the account posts newer than the cursor', function () {
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [
            blueskyReplyNotification('c2', 'at://did:plc:fan/app.bsky.feed.post/c1', '2026-10-16T12:00:00Z'),
            blueskyReplyNotification('c1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:00:00Z'),
            blueskyReplyNotification('old', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T09:00:00Z'),
        ]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => []]),
        "{$this->appview}/xrpc/".BlueskyLexicon::GET_POSTS.'*' => Http::response(['posts' => [[
            'uri' => 'at://did:plc:me/app.bsky.feed.post/root1',
            'cid' => 'cid-root1',
            'indexedAt' => '2026-10-15T10:00:00Z',
            'record' => ['text' => 'My post'],
        ]]]),
    ]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toHaveCount(2)
        ->and($result->cursor)->toBe('2026-10-16T12:00:00Z')
        ->and($result->messages[0]->platformParentId)->toBe('at://did:plc:fan/app.bsky.feed.post/c1')
        ->and($result->messages[1]->platformParentId)->toBeNull()
        ->and($result->messages[1]->subject->text)->toBe('My post')
        ->and($result->messages[1]->subject->remoteId)->toBe('root1')
        ->and($result->messages[1]->meta)->toEqual(['cid' => 'cid-c1']);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'updateSeen'));
});

test('a reply on the account post that mentions the account is a comment', function () {
    $mention = blueskyReplyNotification('c1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:00:00Z');
    data_set($mention, 'reason', 'mention');
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [$mention]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => []]),
        "{$this->appview}/xrpc/*" => Http::response(['posts' => []]),
    ]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->kind)->toBe(ConversationKind::Comment)
        ->and($result->messages[0]->platformMessageId)->toBe('at://did:plc:fan/app.bsky.feed.post/c1');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), BlueskyLexicon::LIST_NOTIFICATIONS)
        && str_contains((string) parse_url($request->url(), PHP_URL_QUERY), 'reasons=mention'));
});

test('poll skips replies on posts of other accounts', function () {
    $foreign = blueskyReplyNotification('c1', 'at://did:plc:other/app.bsky.feed.post/p', '2026-10-16T11:00:00Z');
    data_set($foreign, 'record.reply.root.uri', 'at://did:plc:other/app.bsky.feed.post/p');
    Http::fake(["{$this->service}/xrpc/*" => Http::response(['notifications' => [$foreign]])]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toBe([]);
});

test('reply posts under the parent with the root reference', function () {
    $conversation = InboxConversation::factory()->create([
        'social_account_id' => $this->account->id,
        'platform_conversation_id' => 'at://did:plc:me/app.bsky.feed.post/root1',
        'platform_conversation_hash' => InboxConversation::hashOf('at://did:plc:me/app.bsky.feed.post/root1'),
        'meta' => ['cid' => 'cid-root1'],
    ]);
    $parent = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => 'at://did:plc:fan/app.bsky.feed.post/c1',
        'meta' => ['cid' => 'cid-c1'],
    ]);
    Http::fake(["{$this->service}/xrpc/".BlueskyLexicon::CREATE_RECORD => Http::response(['uri' => 'at://did:plc:me/app.bsky.feed.post/r1', 'cid' => 'cid-r1'])]);

    $sent = app(BlueskyInboxDriver::class)->reply($this->account, $parent, '@fan.bsky.social thanks!');

    expect($sent->platformMessageId)->toBe('at://did:plc:me/app.bsky.feed.post/r1')
        ->and($sent->meta)->toEqual(['cid' => 'cid-r1']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'record.reply.parent.uri') === 'at://did:plc:fan/app.bsky.feed.post/c1'
        && data_get($request->data(), 'record.reply.root.cid') === 'cid-root1');
});

function blueskyOwnReplyRecord(string $rkey, string $parentUri, string $createdAt, string $rootUri = 'at://did:plc:me/app.bsky.feed.post/root1'): array
{
    return [
        'uri' => "at://did:plc:me/app.bsky.feed.post/{$rkey}",
        'cid' => "cid-{$rkey}",
        'value' => [
            'text' => "Own {$rkey}",
            'createdAt' => $createdAt,
            'reply' => [
                'root' => ['uri' => $rootUri, 'cid' => 'cid-root1'],
                'parent' => ['uri' => $parentUri, 'cid' => 'cid-parent'],
            ],
        ],
    ];
}

test('poll asks for reply and mention notifications and posts in the repeated-key form atproto reads', function () {
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [
            blueskyReplyNotification('c1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:00:00Z'),
        ]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => []]),
        "{$this->appview}/xrpc/".BlueskyLexicon::GET_POSTS.'*' => Http::response(['posts' => []]),
    ]);

    app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), BlueskyLexicon::LIST_NOTIFICATIONS)
        && parse_url($request->url(), PHP_URL_QUERY) === 'reasons=reply&reasons=mention&limit=100');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), BlueskyLexicon::GET_POSTS)
        && parse_url($request->url(), PHP_URL_QUERY) === 'uris='.rawurlencode('at://did:plc:me/app.bsky.feed.post/root1'));
    Http::assertNotSent(fn (Request $request): bool => str_contains(rawurldecode($request->url()), '[0]'));
});

test('poll drops notifications that are not replies', function () {
    $like = blueskyReplyNotification('l1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:00:00Z');
    $like['reason'] = 'like';
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [
            $like,
            blueskyReplyNotification('c1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T10:30:00Z'),
        ]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => []]),
        "{$this->appview}/xrpc/*" => Http::response(['posts' => []]),
    ]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['at://did:plc:fan/app.bsky.feed.post/c1']);
});

test('poll picks up replies the account wrote in the Bluesky app', function () {
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => []]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => [
            blueskyOwnReplyRecord('r2', 'at://did:plc:fan/app.bsky.feed.post/c1', '2026-10-16T12:00:00Z'),
            blueskyOwnReplyRecord('thread2', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:30:00Z'),
            blueskyOwnReplyRecord('elsewhere', 'at://did:plc:other/app.bsky.feed.post/x', '2026-10-16T11:00:00Z', 'at://did:plc:other/app.bsky.feed.post/x'),
            blueskyOwnReplyRecord('old', 'at://did:plc:fan/app.bsky.feed.post/c0', '2026-10-16T09:00:00Z'),
        ]]),
        "{$this->appview}/xrpc/*" => Http::response(['posts' => []]),
    ]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->platformMessageId)->toBe('at://did:plc:me/app.bsky.feed.post/r2')
        ->and($result->messages[0]->direction)->toBe(MessageDirection::Outbound)
        ->and($result->messages[0]->platformParentId)->toBe('at://did:plc:fan/app.bsky.feed.post/c1')
        ->and($result->messages[0]->subject->platformId)->toBe('at://did:plc:me/app.bsky.feed.post/root1')
        ->and($result->messages[0]->platformCreatedAt->toIso8601ZuluString())->toBe('2026-10-16T12:00:00Z');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), BlueskyLexicon::LIST_RECORDS)
        && parse_url($request->url(), PHP_URL_QUERY) === 'repo='.rawurlencode('did:plc:me').'&collection=app.bsky.feed.post&limit=100');
});

test('a reply written in the Bluesky app marks the comment answered', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00'));
    config()->set('trypost.inbox.enabled', true);
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [
            blueskyReplyNotification('c1', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T11:00:00Z'),
        ]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => [
            blueskyOwnReplyRecord('r1', 'at://did:plc:fan/app.bsky.feed.post/c1', '2026-10-16T12:00:00Z'),
        ]]),
        "{$this->appview}/xrpc/*" => Http::response(['posts' => []]),
    ]);

    Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'root1']);

    (new SyncInboxAccount($this->account->id, ConversationKind::Comment, true))->handle();

    $comment = InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/c1')->sole();
    expect($comment->answered_at)->not->toBeNull()
        ->and($comment->conversation->open_count)->toBe(0);
});

test('the cursor never moves backwards', function () {
    Http::fake([
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => [
            blueskyReplyNotification('old', 'at://did:plc:me/app.bsky.feed.post/root1', '2026-10-16T09:00:00Z'),
        ]]),
        "{$this->service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => [
            blueskyOwnReplyRecord('older', 'at://did:plc:fan/app.bsky.feed.post/c0', '2026-10-16T08:00:00Z'),
        ]]),
        "{$this->appview}/xrpc/*" => Http::response(['posts' => []]),
    ]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, '2026-10-16T10:00:00Z', CarbonImmutable::parse('2026-09-16'));

    expect($result->cursor)->toBe('2026-10-16T10:00:00Z');
});

test('the driver reads comments and mentions and replies within the account limit', function () {
    $driver = app(BlueskyInboxDriver::class);

    expect($driver->kinds())->toBe([ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage])
        ->and($driver->replyLength($this->account))->toBe(300)
        ->and($driver->poll($this->account, ConversationKind::Review, 'c', CarbonImmutable::now())->messages)->toBe([]);
});
