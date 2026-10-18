<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me', 'username' => 'me.bsky.social']);
    $this->service = config('trypost.platforms.bluesky.default_service');
    $this->appview = config('trypost.platforms.bluesky.public_appview');
});

function blueskyNotification(string $reason, string $rkey, string $indexedAt, ?string $root = null, ?string $parent = null): array
{
    $uri = "at://did:plc:fan/app.bsky.feed.post/{$rkey}";

    return [
        'uri' => $uri,
        'cid' => "cid-{$rkey}",
        'reason' => $reason,
        'indexedAt' => $indexedAt,
        'author' => ['did' => 'did:plc:fan', 'handle' => 'fan.bsky.social', 'displayName' => 'Fan', 'avatar' => 'https://cdn/fan.jpg'],
        'record' => array_filter([
            'text' => "Hey @me.bsky.social {$rkey}",
            'createdAt' => $indexedAt,
            'reply' => $root ? ['root' => ['uri' => $root, 'cid' => 'cid-root'], 'parent' => ['uri' => $parent ?? $root, 'cid' => 'cid-parent']] : null,
        ]),
    ];
}

function fakeBluesky(array $notifications, array $records = [], array $posts = []): void
{
    $service = config('trypost.platforms.bluesky.default_service');
    $appview = config('trypost.platforms.bluesky.public_appview');

    Http::fake([
        "{$service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'*' => Http::response(['notifications' => $notifications]),
        "{$service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'*' => Http::response(['records' => $records]),
        "{$appview}/xrpc/".BlueskyLexicon::GET_POSTS.'*' => Http::response(['posts' => $posts]),
    ]);
}

test('a top-level mention becomes a mention whose subject is the post itself', function () {
    fakeBluesky([blueskyNotification('mention', 'm1', '2026-10-16T12:00:00Z')], posts: [[
        'uri' => 'at://did:plc:fan/app.bsky.feed.post/m1', 'cid' => 'cid-m1', 'indexedAt' => '2026-10-16T12:00:00Z',
        'record' => ['text' => 'Hey @me.bsky.social m1'], 'author' => ['did' => 'did:plc:fan', 'handle' => 'fan.bsky.social', 'displayName' => 'Fan'],
    ]]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'));
    $message = $result->messages[0];

    expect($result->messages)->toHaveCount(1)
        ->and($message->kind)->toBe(ConversationKind::Mention)
        ->and($message->platformParentId)->toBeNull()
        ->and($message->subject->platformId)->toBe('at://did:plc:fan/app.bsky.feed.post/m1')
        ->and($message->subject->participantUsername)->toBe('fan.bsky.social')
        ->and($message->subject->url)->toBe(config('trypost.platforms.bluesky.web_app').'/profile/fan.bsky.social/post/m1')
        ->and($result->cursor)->toBe('2026-10-16T12:00:00Z');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'reasons=mention') && str_contains($request->url(), 'reasons=reply'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'updateSeen'));
});

test('a mention under the channel own post stays a comment', function () {
    fakeBluesky([blueskyNotification('mention', 'c1', '2026-10-16T12:00:00Z', 'at://did:plc:me/app.bsky.feed.post/root1')]);

    $result = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toBe([]);
});

test('a mention inside someone else thread uses the thread root as subject', function () {
    fakeBluesky([blueskyNotification('mention', 'm2', '2026-10-16T12:00:00Z', 'at://did:plc:other/app.bsky.feed.post/r', 'at://did:plc:other/app.bsky.feed.post/p')], posts: [[
        'uri' => 'at://did:plc:other/app.bsky.feed.post/r', 'cid' => 'cid-r', 'indexedAt' => '2026-10-15T12:00:00Z',
        'record' => ['text' => 'Root by other'], 'author' => ['did' => 'did:plc:other', 'handle' => 'other.bsky.social', 'displayName' => 'Other'],
    ]]);

    $message = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'))->messages[0];

    expect($message->subject->platformId)->toBe('at://did:plc:other/app.bsky.feed.post/r')
        ->and($message->subject->text)->toBe('Root by other')
        ->and($message->subject->meta)->toEqual(['cid' => 'cid-root'])
        ->and($message->platformParentId)->toBeNull();
});

test('a reply to the channel answer in a foreign thread is a mention', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:me/app.bsky.feed.post/r1', 'at://did:plc:fan/app.bsky.feed.post/m1', MessageDirection::Outbound));
    fakeBluesky([blueskyNotification('reply', 'm3', '2026-10-16T13:00:00Z', 'at://did:plc:fan/app.bsky.feed.post/m1', 'at://did:plc:me/app.bsky.feed.post/r1')]);

    $message = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'))->messages[0];

    expect($message->kind)->toBe(ConversationKind::Mention)
        ->and($message->platformParentId)->toBe('at://did:plc:me/app.bsky.feed.post/r1');
});

test('the channel own reply under a stored mention is outbound, other own posts are ignored', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    $own = fn (string $rkey, string $root, string $parent): array => [
        'uri' => "at://did:plc:me/app.bsky.feed.post/{$rkey}", 'cid' => "cid-{$rkey}",
        'value' => ['text' => 'Thanks!', 'createdAt' => '2026-10-16T14:00:00Z', 'reply' => ['root' => ['uri' => $root, 'cid' => 'x'], 'parent' => ['uri' => $parent, 'cid' => 'y']]],
    ];
    fakeBluesky([], records: [
        $own('r2', 'at://did:plc:fan/app.bsky.feed.post/m1', 'at://did:plc:fan/app.bsky.feed.post/m1'),
        $own('r3', 'at://did:plc:stranger/app.bsky.feed.post/s', 'at://did:plc:stranger/app.bsky.feed.post/s'),
    ]);

    $messages = app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, CarbonImmutable::parse('2026-09-16'))->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->direction)->toBe(MessageDirection::Outbound)
        ->and($messages[0]->platformMessageId)->toBe('at://did:plc:me/app.bsky.feed.post/r2');
});

test('the comment poll is unchanged', function () {
    fakeBluesky([blueskyNotification('mention', 'm1', '2026-10-16T12:00:00Z')]);

    expect(app(BlueskyInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
});
