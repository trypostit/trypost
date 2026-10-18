<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\MastodonInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class]);
    Sleep::fake();
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo('2026-10-16 12:00:00');
    $this->account = SocialAccount::factory()->mastodon()->create(['platform_user_id' => '100', 'username' => 'brand']);
    $this->instance = $this->account->mastodonInstance();
    $this->driver = app(MastodonInboxDriver::class);
    $this->status = fn (string $id, string $accountId, string $acct, string $at, string $visibility = 'direct', ?array $mentions = null) => [
        'id' => $id, 'created_at' => $at, 'visibility' => $visibility, 'content' => "<p>Hello from {$acct}</p>",
        'url' => "{$this->instance}/@{$acct}/{$id}", 'in_reply_to_id' => null, 'media_attachments' => [],
        'mentions' => array_map(fn (string $mentioned) => ['id' => $mentioned], $mentions ?? [$accountId === '100' ? '200' : '100']),
        'account' => ['id' => $accountId, 'acct' => $acct, 'display_name' => ucfirst($acct), 'avatar' => "https://cdn.example/{$acct}.png"],
    ];
    $this->dm = fn (array $attributes = []) => InboxConversation::factory()->for($this->account, 'socialAccount')->create([
        'kind' => ConversationKind::DirectMessage,
        'platform_conversation_id' => 'c-1',
        'platform_conversation_hash' => InboxConversation::hashOf('c-1'),
        'participant_username' => 'ana@example.social',
        ...$attributes,
    ]);
});

test('a 1:1 conversation becomes a DM conversation with its direct statuses', function () {
    Http::fake([
        "{$this->instance}/api/v1/conversations*" => Http::response([
            ['id' => 'c-1', 'unread' => true, 'accounts' => [['id' => '200', 'acct' => 'ana@example.social', 'display_name' => 'Ana', 'avatar' => 'https://cdn.example/ana.png']], 'last_status' => ($this->status)('s-2', '100', 'brand', '2026-10-16T11:00:00.000Z')],
            ['id' => 'c-group', 'unread' => false, 'accounts' => [['id' => '201', 'acct' => 'a'], ['id' => '202', 'acct' => 'b']], 'last_status' => ($this->status)('g-1', '201', 'a', '2026-10-16T11:00:00.000Z')],
        ]),
        "{$this->instance}/api/v1/statuses/s-2/context" => Http::response([
            'ancestors' => [($this->status)('p-1', '200', 'ana@example.social', '2026-10-16T09:00:00.000Z', 'public'), ($this->status)('s-1', '200', 'ana@example.social', '2026-10-16T10:00:00.000Z')],
            'descendants' => [],
        ]),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));
    $messages = collect($result->messages)->keyBy('platformMessageId');

    expect($messages->keys()->sort()->values()->all())->toBe(['s-1', 's-2'])
        ->and($messages['s-1']->direction)->toBe(MessageDirection::Inbound)
        ->and($messages['s-2']->direction)->toBe(MessageDirection::Outbound)
        ->and($messages['s-1']->body)->toBe('Hello from ana@example.social')
        ->and($messages['s-1']->subject->platformId)->toBe('c-1')
        ->and($messages['s-1']->subject->participantUsername)->toBe('ana@example.social')
        ->and($result->cursor)->toBe('2026-10-16T11:00:00+00:00');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'g-1/context'));
});

test('a private mention to several people is skipped', function () {
    Http::fake(["{$this->instance}/api/v1/conversations*" => Http::response([
        ['id' => 'c-group', 'unread' => false, 'accounts' => [['id' => '201', 'acct' => 'a'], ['id' => '202', 'acct' => 'b']], 'last_status' => ($this->status)('g-1', '201', 'a', '2026-10-16T11:00:00.000Z')],
    ])]);

    expect($this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/context'));
});

test('a rate limit keeps what was read and resumes after it', function () {
    Http::fake([
        "{$this->instance}/api/v1/conversations*" => Http::response([
            ['id' => 'c-2', 'accounts' => [['id' => '300', 'acct' => 'bo']], 'last_status' => ($this->status)('s-20', '300', 'bo', '2026-10-16T11:30:00.000Z')],
            ['id' => 'c-1', 'accounts' => [['id' => '200', 'acct' => 'ana']], 'last_status' => ($this->status)('s-10', '200', 'ana', '2026-10-16T11:00:00.000Z')],
        ]),
        "{$this->instance}/api/v1/statuses/s-10/context" => Http::response(['ancestors' => [], 'descendants' => []]),
        "{$this->instance}/api/v1/statuses/s-20/context" => Http::response(['error' => 'Too many requests'], 429),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['s-10'])
        ->and($result->cursor)->toBe('2026-10-16T11:00:00+00:00')
        ->and($result->retryAt)->not->toBeNull();
});

test('a reply is a direct status that mentions the participant', function () {
    Http::fake(["{$this->instance}/api/v1/statuses" => Http::response(['id' => 's-9', 'url' => "{$this->instance}/@brand/s-9", 'created_at' => '2026-10-16T12:00:00.000Z'])]);
    $conversation = ($this->dm)();
    InboxMessage::factory()->for($conversation, 'conversation')->create(['social_account_id' => $this->account->id, 'platform_message_id' => 's-2', 'platform_message_hash' => InboxConversation::hashOf('s-2'), 'platform_created_at' => now()->subHour()]);

    $text = $this->driver->directMessageText($conversation, 'Thanks!');
    $sent = $this->driver->sendDirectMessage($this->account, $conversation, $text);

    expect($text)->toBe('@ana@example.social Thanks!')
        ->and($this->driver->directMessageText($conversation, '@ana@example.social Thanks!'))->toBe('@ana@example.social Thanks!')
        ->and($sent->platformMessageId)->toBe('s-9');
    Http::assertSent(fn (Request $request) => $request['visibility'] === 'direct' && $request['in_reply_to_id'] === 's-2' && $request['status'] === '@ana@example.social Thanks!');
});

test('a direct status in notifications is not stored as a comment or mention', function () {
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([
            ['id' => 'n-1', 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'account' => ['id' => '200', 'acct' => 'ana@example.social'], 'status' => [...($this->status)('s-1', '200', 'ana@example.social', '2026-10-16T10:00:00.000Z'), 'in_reply_to_id' => 'own-post']],
        ]),
        '*' => Http::response([]),
    ]);

    foreach ([ConversationKind::Comment, ConversationKind::Mention] as $kind) {
        expect($this->driver->poll($this->account, $kind, null, CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
    }
});

test('Mastodon DMs have no reply window and use the account limit', function () {
    expect($this->driver->replyWindowEndsAt(CarbonImmutable::now()))->toBeNull()
        ->and($this->driver->directMessageLimit($this->account)->max)->toBe($this->account->maxContentLength());
});

test('a status of the thread that also reaches someone else is left out', function () {
    Http::fake([
        "{$this->instance}/api/v1/conversations*" => Http::response([
            ['id' => 'c-1', 'accounts' => [['id' => '200', 'acct' => 'ana@example.social']], 'last_status' => ($this->status)('s-2', '100', 'brand', '2026-10-16T11:00:00.000Z')],
        ]),
        "{$this->instance}/api/v1/statuses/s-2/context" => Http::response([
            'ancestors' => [($this->status)('s-1', '200', 'ana@example.social', '2026-10-16T10:00:00.000Z', mentions: ['100', '300'])],
            'descendants' => [],
        ]),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['s-2']);
});

test('the handle counts as written only as a whole mention', function () {
    $conversation = ($this->dm)(['participant_username' => 'ana']);

    expect($this->driver->directMessageText($conversation, 'Hi @anastasia'))->toBe('@ana Hi @anastasia')
        ->and($this->driver->directMessageText($conversation, 'Hi @ana!'))->toBe('Hi @ana!')
        ->and($this->driver->directMessageText($conversation, 'Hi @ana@example.social'))->toBe('@ana Hi @ana@example.social')
        ->and($this->driver->directMessageText($conversation, 'Thanks @ana.'))->toBe('Thanks @ana.');
});

test('a reply answers the latest message that was not deleted', function () {
    Http::fake(["{$this->instance}/api/v1/statuses" => Http::response(['id' => 's-9', 'created_at' => '2026-10-16T12:00:00.000Z'])]);
    $conversation = ($this->dm)();
    InboxMessage::factory()->for($conversation, 'conversation')->create(['social_account_id' => $this->account->id, 'platform_message_id' => 's-1', 'platform_message_hash' => InboxConversation::hashOf('s-1'), 'platform_created_at' => now()->subHours(2)]);
    InboxMessage::factory()->for($conversation, 'conversation')->create(['social_account_id' => $this->account->id, 'platform_message_id' => 's-2', 'platform_message_hash' => InboxConversation::hashOf('s-2'), 'platform_created_at' => now()->subHour(), 'removed_at' => now()]);

    $this->driver->sendDirectMessage($this->account, $conversation, '@ana@example.social Hi');

    Http::assertSent(fn (Request $request) => $request['in_reply_to_id'] === 's-1');
});

test('a conversation whose last status is gone moves the watermark past it', function () {
    Http::fake([
        "{$this->instance}/api/v1/conversations*" => Http::response([
            ['id' => 'c-1', 'accounts' => [['id' => '200', 'acct' => 'ana']], 'last_status' => ($this->status)('s-10', '200', 'ana', '2026-10-16T11:00:00.000Z')],
        ]),
        "{$this->instance}/api/v1/statuses/s-10/context" => Http::response(['error' => 'Record not found'], 404),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, '2026-10-16T10:00:00+00:00', CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toBe([])
        ->and($result->cursor)->toBe('2026-10-16T11:00:00+00:00');
});
