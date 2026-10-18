<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\InboxDrivers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class]);
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo('2026-10-16 12:00:00');
    $this->account = SocialAccount::factory()->bluesky()->create([
        'platform_user_id' => 'did:plc:me',
        'username' => 'brand.bsky.social',
        'access_token' => inboxBlueskyJwt('com.atproto.appPassPrivileged'),
        'token_expires_at' => null,
    ]);
    $this->service = config('trypost.platforms.bluesky.default_service');
    $this->driver = app(BlueskyInboxDriver::class);
    $member = fn (string $did, string $handle) => ['did' => $did, 'handle' => $handle, 'displayName' => ucfirst(explode('.', $handle)[0]), 'avatar' => "https://cdn.example/{$handle}.jpg"];
    $this->convos = ['convos' => [
        ['id' => 'convo-1', 'rev' => '3', 'members' => [$member('did:plc:me', 'brand.bsky.social'), $member('did:plc:ana', 'ana.bsky.social')], 'lastMessage' => ['id' => 'msg-2', 'sentAt' => '2026-10-16T11:00:00Z', 'sender' => ['did' => 'did:plc:me'], 'text' => 'Sure!']],
        ['id' => 'convo-group', 'rev' => '2', 'members' => [$member('did:plc:me', 'brand.bsky.social'), $member('did:plc:a', 'a.bsky.social'), $member('did:plc:b', 'b.bsky.social')], 'lastMessage' => ['id' => 'g-1', 'sentAt' => '2026-10-16T11:00:00Z', 'sender' => ['did' => 'did:plc:a'], 'text' => 'Hey all']],
        ['id' => 'convo-old', 'rev' => '1', 'members' => [$member('did:plc:me', 'brand.bsky.social'), $member('did:plc:old', 'old.bsky.social')], 'lastMessage' => ['id' => 'o-1', 'sentAt' => '2026-08-01T11:00:00Z', 'sender' => ['did' => 'did:plc:old'], 'text' => 'Old']],
    ]];
});

test('direct convos become DM conversations and group convos are skipped', function () {
    Http::fake([
        "{$this->service}/xrpc/chat.bsky.convo.listConvos*" => Http::response($this->convos),
        "{$this->service}/xrpc/chat.bsky.convo.getMessages*" => Http::response(['messages' => [
            ['id' => 'msg-2', 'rev' => '3', 'text' => 'Sure!', 'sender' => ['did' => 'did:plc:me'], 'sentAt' => '2026-10-16T11:00:00Z'],
            ['id' => 'msg-1', 'rev' => '2', 'text' => 'Can I ask something?', 'sender' => ['did' => 'did:plc:ana'], 'sentAt' => '2026-10-16T10:00:00Z'],
            ['id' => 'msg-0', 'rev' => '1', 'sender' => ['did' => 'did:plc:ana'], 'sentAt' => '2026-10-16T09:00:00Z'],
        ]]),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));
    $messages = collect($result->messages)->keyBy('platformMessageId');

    expect($messages->keys()->sort()->values()->all())->toBe(['chat:convo-1:msg-0', 'chat:convo-1:msg-1', 'chat:convo-1:msg-2'])
        ->and($messages['chat:convo-1:msg-1']->direction)->toBe(MessageDirection::Inbound)
        ->and($messages['chat:convo-1:msg-2']->direction)->toBe(MessageDirection::Outbound)
        ->and($messages['chat:convo-1:msg-0']->removed)->toBeTrue()
        ->and($messages['chat:convo-1:msg-1']->subject->platformId)->toBe('convo-1')
        ->and($messages['chat:convo-1:msg-1']->subject->participantUsername)->toBe('ana.bsky.social')
        ->and($messages['chat:convo-1:msg-1']->subject->url)->toBe(config('trypost.platforms.bluesky.web_app').'/messages/convo-1')
        ->and($result->cursor)->toBe('2026-10-16T11:00:00+00:00');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'chat.bsky.convo.listConvos')
        && str_contains($request->url(), 'kind=direct')
        && $request->hasHeader('atproto-proxy', 'did:web:api.bsky.chat#bsky_chat'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'convoId=convo-group') || str_contains($request->url(), 'convoId=convo-old'));
});

test('group convos are skipped', function () {
    Http::fake([
        "{$this->service}/xrpc/chat.bsky.convo.listConvos*" => Http::response(['convos' => [$this->convos['convos'][1]]]),
        '*' => Http::response(['messages' => []]),
    ]);

    expect($this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'))->messages)->toBe([]);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'getMessages'));
});

test('a send goes to the convo through the chat proxy', function () {
    Http::fake(["{$this->service}/xrpc/chat.bsky.convo.sendMessage" => Http::response(['id' => 'msg-9', 'rev' => '9', 'text' => 'Hi', 'sender' => ['did' => 'did:plc:me'], 'sentAt' => '2026-10-16T12:00:00Z'])]);
    $conversation = InboxConversation::factory()->for($this->account, 'socialAccount')->create([
        'kind' => ConversationKind::DirectMessage,
        'platform_conversation_id' => 'convo-1',
        'platform_conversation_hash' => InboxConversation::hashOf('convo-1'),
    ]);

    $sent = $this->driver->sendDirectMessage($this->account, $conversation, 'Hi');

    expect($sent->platformMessageId)->toBe('chat:convo-1:msg-9');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request['convoId'] === 'convo-1'
        && $request['message'] === ['text' => 'Hi']
        && $request->hasHeader('atproto-proxy', 'did:web:api.bsky.chat#bsky_chat'));
});

test('no chat request without DM access', function () {
    $this->account->update(['access_token' => inboxBlueskyJwt('com.atproto.appPass')]);
    Http::fake();

    (new SyncInboxAccount($this->account->id, ConversationKind::DirectMessage))->handle();

    expect(app(InboxDrivers::class)->kindsFor($this->account->fresh()))->not->toContain(ConversationKind::DirectMessage);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'chat.bsky'));
});

test('Bluesky DMs have no reply window and a 1000 character limit', function () {
    expect($this->driver->replyWindowEndsAt(CarbonImmutable::now()))->toBeNull()
        ->and($this->driver->directMessageLimit($this->account)->max)->toBe(1000);
});

test('the connect page asks for DM access while the inbox is on', function () {
    $owner = $this->account->workspace->owner;
    $owner->update(['current_workspace_id' => $this->account->workspace_id]);

    $this->actingAs($owner)
        ->get(route('app.social.bluesky.connect'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('asksForMessageAccess', true));

    config()->set('trypost.inbox.enabled', false);

    $this->actingAs($owner)
        ->get(route('app.social.bluesky.connect'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('asksForMessageAccess', false));
});

test('a poll stopped at its page budget resumes from the convo cursor', function () {
    $sequence = Http::sequence();
    foreach (range(0, 9) as $number) {
        $sequence->push(['convos' => [], 'cursor' => "c{$number}"]);
    }
    $sequence->push(['convos' => [$this->convos['convos'][0]]]);
    Http::fake([
        "{$this->service}/xrpc/chat.bsky.convo.listConvos*" => $sequence,
        "{$this->service}/xrpc/chat.bsky.convo.getMessages*" => Http::response(['messages' => []]),
    ]);

    $first = $this->driver->poll($this->account, ConversationKind::DirectMessage, '2026-10-15T00:00:00+00:00', CarbonImmutable::parse('2026-09-16'));

    expect(json_decode($first->cursor, true))->toEqual(['watermark' => '2026-10-15T00:00:00+00:00', 'after' => 'c9', 'newest' => null]);

    $second = $this->driver->poll($this->account, ConversationKind::DirectMessage, $first->cursor, CarbonImmutable::parse('2026-09-16'));

    expect($second->cursor)->toBe('2026-10-16T11:00:00+00:00');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'listConvos') && str_contains($request->url(), 'cursor=c9'));
});

test('every chat call goes to the PDS from the DID document, not the entryway', function () {
    $pds = 'https://hollowfoot.us-west.host.bsky.network';
    Http::fake([
        config('trypost.platforms.bluesky.plc_directory').'/did%3Aplc%3Ame' => Http::response(['id' => 'did:plc:me', 'service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $pds]]]),
        "{$pds}/xrpc/chat.bsky.convo.listConvos*" => Http::response(['convos' => [$this->convos['convos'][0]]]),
        "{$pds}/xrpc/chat.bsky.convo.getMessages*" => Http::response(['messages' => [
            ['id' => 'msg-1', 'rev' => '2', 'text' => 'Can I ask something?', 'sender' => ['did' => 'did:plc:ana'], 'sentAt' => '2026-10-16T10:00:00Z'],
        ]]),
        "{$pds}/xrpc/chat.bsky.convo.sendMessage" => Http::response(['id' => 'msg-9', 'sentAt' => '2026-10-16T12:00:00Z']),
    ]);
    $conversation = InboxConversation::factory()->for($this->account, 'socialAccount')->create([
        'kind' => ConversationKind::DirectMessage,
        'platform_conversation_id' => 'convo-1',
        'platform_conversation_hash' => InboxConversation::hashOf('convo-1'),
    ]);

    $result = $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));
    $this->driver->sendDirectMessage($this->account, $conversation, 'Hi');

    expect($result->messages)->toHaveCount(1);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$pds}/xrpc/chat.bsky.convo.listConvos") && $request->hasHeader('atproto-proxy', 'did:web:api.bsky.chat#bsky_chat'));
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$pds}/xrpc/chat.bsky.convo.getMessages"));
    Http::assertSent(fn (Request $request) => $request->url() === "{$pds}/xrpc/chat.bsky.convo.sendMessage");
    Http::assertNotSent(fn (Request $request) => str_starts_with($request->url(), "{$this->service}/xrpc/chat.bsky"));
});

test('chat falls back to the stored service when the DID document cannot be read', function () {
    Http::fake([
        config('trypost.platforms.bluesky.plc_directory').'/*' => Http::response([], 500),
        "{$this->service}/xrpc/chat.bsky.convo.listConvos*" => Http::response(['convos' => []]),
    ]);

    $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));

    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), "{$this->service}/xrpc/chat.bsky.convo.listConvos"));
});

test('the PDS endpoint is read once per DID and remembered across polls', function () {
    $pds = 'https://hollowfoot.us-west.host.bsky.network';
    Http::fake([
        config('trypost.platforms.bluesky.plc_directory').'/*' => Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => $pds]]]),
        "{$pds}/xrpc/chat.bsky.convo.listConvos*" => Http::response(['convos' => []]),
    ]);

    $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));
    $this->driver->poll($this->account, ConversationKind::DirectMessage, null, CarbonImmutable::parse('2026-09-16'));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_starts_with($request->url(), config('trypost.platforms.bluesky.plc_directory')));
});
