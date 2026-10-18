<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\MastodonInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->account = SocialAccount::factory()->mastodon()->create(['platform_user_id' => '1', 'username' => 'me']);
    $this->instance = $this->account->mastodonInstance();
    $this->since = CarbonImmutable::parse('2026-09-16');
});

function mastodonMention(string $id, string $visibility = 'public', ?string $inReplyTo = null, ?string $inReplyToAccount = null): array
{
    return [
        'id' => "n{$id}",
        'type' => 'mention',
        'created_at' => '2026-10-16T12:00:00.000Z',
        'account' => ['id' => '9', 'acct' => 'fan@other.social', 'display_name' => 'Fan', 'avatar' => 'https://cdn/fan.png'],
        'status' => [
            'id' => $id,
            'uri' => "https://other.social/users/fan/statuses/{$id}",
            'url' => "https://other.social/@fan/{$id}",
            'created_at' => '2026-10-16T12:00:00.000Z',
            'visibility' => $visibility,
            'in_reply_to_id' => $inReplyTo,
            'in_reply_to_account_id' => $inReplyToAccount,
            'spoiler_text' => '',
            'content' => '<p>Hi <span class="h-card"><a href="https://mastodon.social/@me">@me</a></span></p>',
            'media_attachments' => [['type' => 'image', 'url' => 'https://cdn/a.png', 'preview_url' => 'https://cdn/a-small.png']],
            'account' => ['id' => '9', 'acct' => 'fan@other.social', 'display_name' => 'Fan', 'avatar' => 'https://cdn/fan.png'],
            'mentions' => [['id' => '1', 'acct' => 'me']],
        ],
    ];
}

function mastodonMentionsFake(string $instance, array $notifications, array $ownStatuses = [], array $contexts = []): void
{
    Http::fake([
        "{$instance}/api/v1/notifications*" => Http::response($notifications),
        "{$instance}/api/v1/accounts/1/statuses*" => Http::response($ownStatuses),
        ...collect($contexts)->mapWithKeys(fn (array $context, string $id): array => ["{$instance}/api/v1/statuses/{$id}/context" => Http::response($context)])->all(),
    ]);
}

test('a top-level mention becomes a mention whose subject is the status itself', function () {
    mastodonMentionsFake($this->instance, [mastodonMention('100')]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since);
    $message = $result->messages[0];

    expect($result->messages)->toHaveCount(1)
        ->and($message->kind)->toBe(ConversationKind::Mention)
        ->and($message->subject->platformId)->toBe('100')
        ->and($message->subject->text)->toBe('Hi @me')
        ->and($message->subject->media)->toEqual([['type' => 'image', 'url' => 'https://cdn/a.png', 'thumbnail_url' => 'https://cdn/a-small.png']])
        ->and($message->subject->participantUsername)->toBe('fan@other.social')
        ->and($message->subject->participantName)->toBe('Fan')
        ->and($message->platformParentId)->toBeNull()
        ->and($message->direction)->toBe(MessageDirection::Inbound)
        ->and($message->body)->toBe('Hi @me')
        ->and(json_decode((string) $result->cursor, true))->toEqual(['notifications' => 'n100', 'statuses' => null, 'backlog' => []]);
    Http::assertSent(fn (Request $request): bool => str_contains(urldecode($request->url()), 'types[]=mention'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/context'));
});

test('direct statuses are not mentions', function () {
    mastodonMentionsFake($this->instance, [mastodonMention('101', 'direct')]);

    expect(app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since)->messages)->toBe([]);
});

test('a mention in a thread on the channel own status is left to comments', function () {
    mastodonMentionsFake($this->instance, [
        mastodonMention('102', inReplyTo: '50', inReplyToAccount: '1'),
        mastodonMention('103', inReplyTo: '60', inReplyToAccount: '7'),
    ], contexts: [
        '103' => ['ancestors' => [['id' => '50', 'account' => ['id' => '1']], ['id' => '60', 'account' => ['id' => '7']]], 'descendants' => []],
    ]);

    expect(app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since)->messages)->toBe([]);
});

test('a mention inside someone else thread is a mention of its own', function () {
    mastodonMentionsFake($this->instance, [mastodonMention('104', inReplyTo: '60', inReplyToAccount: '7')], contexts: [
        '104' => ['ancestors' => [['id' => '59', 'account' => ['id' => '7']], ['id' => '60', 'account' => ['id' => '7']]], 'descendants' => []],
    ]);

    $message = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since)->messages[0];

    expect($message->subject->platformId)->toBe('104')
        ->and($message->platformParentId)->toBeNull();
});

test('a reply to the channel answer keeps its parent and lands in the mention', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('100', subjectId: '100'));
    UpsertInboxMessage::execute($this->account, inboxMention('200', '100', MessageDirection::Outbound, subjectId: '100'));
    mastodonMentionsFake($this->instance, [mastodonMention('103', inReplyTo: '200', inReplyToAccount: '1')]);

    $message = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since)->messages[0];
    UpsertInboxMessage::execute($this->account, $message);

    expect($message->platformParentId)->toBe('200')
        ->and(InboxConversation::query()->sole()->open_count)->toBe(1);
});

test('the channel own reply to a stored mention is outbound, other own statuses are ignored', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('100', subjectId: '100'));
    mastodonMentionsFake($this->instance, [], [
        ['id' => '201', 'in_reply_to_id' => '100', 'created_at' => '2026-10-16T13:00:00.000Z', 'visibility' => 'public', 'spoiler_text' => '', 'content' => '<p>Thanks</p>', 'url' => 'https://mastodon.social/@me/201', 'media_attachments' => [], 'account' => ['id' => '1', 'acct' => 'me']],
        ['id' => '202', 'in_reply_to_id' => '999', 'created_at' => '2026-10-16T13:00:00.000Z', 'visibility' => 'public', 'spoiler_text' => '', 'content' => '<p>Elsewhere</p>', 'url' => 'https://mastodon.social/@me/202', 'media_attachments' => [], 'account' => ['id' => '1', 'acct' => 'me']],
    ]);

    $messages = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since)->messages;

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->direction)->toBe(MessageDirection::Outbound)
        ->and($messages[0]->platformMessageId)->toBe('201')
        ->and($messages[0]->platformParentId)->toBe('100');
});

test('the mention poll resumes after the newest notification it read', function () {
    mastodonMentionsFake($this->instance, []);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, json_encode(['notifications' => 'n90', 'statuses' => '300']), $this->since);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/notifications') && str_contains($request->url(), 'min_id=n90'));
    expect(json_decode((string) $result->cursor, true))->toEqual(['notifications' => 'n90', 'statuses' => '300', 'backlog' => []]);
});

test('the comment poll is unchanged by mentions', function () {
    mastodonMentionsFake($this->instance, [mastodonMention('100')]);

    expect(app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since)->messages)->toBe([])
        ->and(app(MastodonInboxDriver::class)->kinds())->toBe([ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage]);
});

test('mentions over the call cap wait in the backlog and are read on the next poll', function () {
    $notifications = collect(range(1, MastodonInboxDriver::MAX_CONTEXT_CALLS + 1))
        ->map(fn (int $i): array => mastodonMention((string) (500 + $i), inReplyTo: '60', inReplyToAccount: '7'))
        ->all();
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::sequence()->push($notifications)->push([]),
        "{$this->instance}/api/v1/accounts/1/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/*/context" => Http::response(['ancestors' => [['id' => '60', 'account' => ['id' => '7']]], 'descendants' => []]),
        "{$this->instance}/api/v1/statuses/*" => Http::response(mastodonMention('561', inReplyTo: '60', inReplyToAccount: '7')['status']),
    ]);

    $first = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, null, $this->since);
    $backlog = data_get(json_decode((string) $first->cursor, true), 'backlog');

    expect($first->messages)->toHaveCount(MastodonInboxDriver::MAX_CONTEXT_CALLS)
        ->and($backlog)->toBe(['561']);

    $second = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, $first->cursor, $this->since);

    expect(collect($second->messages)->pluck('platformMessageId')->all())->toBe(['561'])
        ->and(data_get(json_decode((string) $second->cursor, true), 'backlog'))->toBe([]);
});

test('a backlog mention answering a stored message keeps it as parent', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('100', subjectId: '100'));
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/1/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/*" => Http::response(mastodonMention('600', inReplyTo: '100', inReplyToAccount: '9')['status']),
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Mention, json_encode(['notifications' => 'n1', 'statuses' => '1', 'backlog' => ['600']]), $this->since);

    expect($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->platformParentId)->toBe('100');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/context'));
});
