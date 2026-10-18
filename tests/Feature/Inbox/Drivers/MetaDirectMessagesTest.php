<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Inbox\DirectMessagesUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Meta\MetaDirectMessages;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    $this->travelTo('2026-10-16 12:00:00');
    $this->instagram = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => 'app-scoped-1',
        'meta' => ['ig_user_id' => 'ig-me'],
        'token_expires_at' => null,
    ]);
    $this->facebook = SocialAccount::factory()->facebook()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    $this->service = app(MetaDirectMessages::class);
    $this->igGraph = config('trypost.platforms.instagram.graph_api');
    $this->fbGraph = config('trypost.platforms.facebook.graph_api');
    $this->dm = fn (SocialAccount $account, string $participant, array $attributes = []) => InboxConversation::factory()->for($account, 'socialAccount')->create([
        'workspace_id' => $account->workspace_id,
        'kind' => ConversationKind::DirectMessage,
        'platform_conversation_id' => $participant,
        'platform_conversation_hash' => InboxConversation::hashOf($participant),
        ...$attributes,
    ]);
});

test('the Instagram poll reads conversations, their messages and the participant', function () {
    Http::fake([
        "{$this->igGraph}/me/conversations*" => Http::response(['data' => [
            ['id' => 'conv-new', 'updated_time' => '2026-10-16T11:00:00+0000'],
            ['id' => 'conv-old', 'updated_time' => '2026-09-01T11:00:00+0000'],
        ]]),
        "{$this->igGraph}/conv-new*" => Http::response(['messages' => ['data' => [
            ['id' => 'mid-2', 'created_time' => '2026-10-16T11:00:00+0000'],
            ['id' => 'mid-1', 'created_time' => '2026-10-16T10:00:00+0000'],
        ]]]),
        "{$this->igGraph}/mid-1*" => Http::response(['id' => 'mid-1', 'created_time' => '2026-10-16T10:00:00+0000', 'from' => ['id' => 'igsid-9', 'username' => 'ana.fan'], 'to' => ['data' => [['id' => 'ig-me', 'username' => 'brand']]], 'message' => 'Do you ship to Lisbon?']),
        "{$this->igGraph}/mid-2*" => Http::response(['id' => 'mid-2', 'created_time' => '2026-10-16T11:00:00+0000', 'from' => ['id' => 'ig-me', 'username' => 'brand'], 'to' => ['data' => [['id' => 'igsid-9', 'username' => 'ana.fan']]], 'message' => 'We do!']),
        "{$this->igGraph}/igsid-9*" => Http::response(['name' => 'Ana Fan', 'username' => 'ana.fan', 'profile_pic' => 'https://cdn.example/ana.jpg']),
    ]);

    $result = $this->service->poll($this->instagram, null, CarbonImmutable::parse('2026-09-16'));
    $messages = collect($result->messages)->keyBy('platformMessageId');

    expect($result->messages)->toHaveCount(2)
        ->and($messages['mid-1']->kind)->toBe(ConversationKind::DirectMessage)
        ->and($messages['mid-1']->subject->platformId)->toBe('igsid-9')
        ->and($messages['mid-2']->subject->platformId)->toBe('igsid-9')
        ->and($messages['mid-1']->subject->participantName)->toBe('Ana Fan')
        ->and($messages['mid-1']->subject->participantAvatarUrl)->toBe('https://cdn.example/ana.jpg')
        ->and($messages['mid-1']->direction)->toBe(MessageDirection::Inbound)
        ->and($messages['mid-1']->body)->toBe('Do you ship to Lisbon?')
        ->and($messages['mid-2']->direction)->toBe(MessageDirection::Outbound)
        ->and($result->cursor)->toBe('2026-10-16T11:00:00+00:00');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'conv-old'));
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/conversations') && $request['platform'] === 'instagram');
});

test('the poll stops at the cursor', function () {
    Http::fake(["{$this->igGraph}/me/conversations*" => Http::response(['data' => [
        ['id' => 'conv-1', 'updated_time' => '2026-10-16T09:00:00+0000'],
    ]])]);

    $result = $this->service->poll($this->instagram, '2026-10-16T10:00:00+00:00', CarbonImmutable::parse('2026-09-16'));

    expect($result->messages)->toBe([])
        ->and($result->cursor)->toBe('2026-10-16T10:00:00+00:00');
});

test('a Messenger send answers inside the window', function () {
    Http::fake(["{$this->fbGraph}/page-1/messages*" => Http::response(['recipient_id' => 'psid-1', 'message_id' => 'm_abc'])]);
    $conversation = ($this->dm)($this->facebook, 'psid-1', ['last_inbound_at' => now()->subHour()]);

    $sent = $this->service->send($this->facebook, $conversation, 'Hello');

    expect($sent->platformMessageId)->toBe('m_abc');
    Http::assertSent(fn (Request $request) => json_decode($request['recipient'], true) === ['id' => 'psid-1']
        && json_decode($request['message'], true) === ['text' => 'Hello']
        && $request['messaging_type'] === 'RESPONSE'
        && $request['access_token'] === $this->facebook->access_token);
});

test('the window is 24 hours', function () {
    expect($this->service->windowEndsAt(CarbonImmutable::parse('2026-10-16 10:00:00'))->toIso8601String())->toBe('2026-10-17T10:00:00+00:00')
        ->and($this->service->windowEndsAt(null))->toBeNull();
});

test('an Instagram Login send posts the documented body without a messaging type', function () {
    Http::fake(["{$this->igGraph}/me/messages*" => Http::response(['recipient_id' => 'igsid-9', 'message_id' => 'mid-9'])]);
    $conversation = ($this->dm)($this->instagram, 'igsid-9', ['last_inbound_at' => now()->subHour()]);

    expect($this->service->send($this->instagram, $conversation, 'Hello')->platformMessageId)->toBe('mid-9');
    Http::assertSent(fn (Request $request) => json_decode($request['recipient'], true) === ['id' => 'igsid-9'] && ! isset($request['messaging_type']));
});

test('an invalid token is reported as expired', function () {
    Http::fake(["{$this->fbGraph}/page-1/messages*" => Http::response(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']], 400)]);
    $conversation = ($this->dm)($this->facebook, 'psid-1', ['last_inbound_at' => now()]);

    expect(fn () => $this->service->send($this->facebook, $conversation, 'Hello'))->toThrow(TokenExpiredException::class);
});

test('webhook events become DMs', function (array $message, MessageDirection $direction, bool $removed) {
    Http::fake(["{$this->igGraph}/igsid-9*" => Http::response(['name' => 'Ana Fan', 'username' => 'ana.fan'])]);
    $isEcho = $direction === MessageDirection::Outbound;

    $incoming = $this->service->parseEvent($this->instagram, [
        'sender' => ['id' => $isEcho ? 'ig-me' : 'igsid-9'],
        'recipient' => ['id' => $isEcho ? 'igsid-9' : 'ig-me'],
        'timestamp' => 1760608800000,
        'message' => $message,
    ]);

    expect($incoming->direction)->toBe($direction)
        ->and($incoming->subject->platformId)->toBe('igsid-9')
        ->and($incoming->subject->participantUsername)->toBe('ana.fan')
        ->and($incoming->removed)->toBe($removed)
        ->and($incoming->platformCreatedAt->toIso8601String())->toBe('2025-10-16T10:00:00+00:00');
})->with([
    'inbound' => [['mid' => 'mid-1', 'text' => 'Hi'], MessageDirection::Inbound, false],
    'echo' => [['mid' => 'mid-2', 'text' => 'Hello', 'is_echo' => true], MessageDirection::Outbound, false],
    'deleted' => [['mid' => 'mid-1', 'is_deleted' => true], MessageDirection::Inbound, true],
]);

test('images and videos become media and events without a message are ignored', function () {
    Http::fake([
        "{$this->igGraph}/me/conversations*" => Http::response(['data' => []]),
        "{$this->igGraph}/igsid-9*" => Http::response(['error' => ['code' => 100, 'message' => 'Unsupported']], 400),
    ]);

    $withImage = $this->service->parseEvent($this->instagram, [
        'sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608800000,
        'message' => ['mid' => 'mid-3', 'attachments' => [
            ['type' => 'image', 'payload' => ['url' => 'https://cdn.example/a.jpg']],
            ['type' => 'audio', 'payload' => ['url' => 'https://cdn.example/a.mp4']],
        ]],
    ]);
    $read = $this->service->parseEvent($this->instagram, ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'read' => ['mid' => 'mid-1']]);

    expect($withImage->media)->toEqual([['type' => 'image', 'url' => 'https://cdn.example/a.jpg']])
        ->and($withImage->subject->participantName)->toBeNull()
        ->and($read)->toBeNull();
});

test('limits follow the network', function () {
    expect($this->service->limit($this->instagram)->unit)->toBe(ReplyLengthUnit::Bytes)
        ->and($this->service->limit($this->instagram)->max)->toBe(1000)
        ->and($this->service->limit($this->facebook)->unit)->toBe(ReplyLengthUnit::Characters)
        ->and($this->service->limit($this->facebook)->max)->toBe(2000);
});

test('an Instagram account connected through Facebook talks to its Page', function () {
    $account = SocialAccount::factory()->instagramFacebook()->create(['platform_user_id' => 'ig-biz', 'meta' => ['page_id' => 'page-7'], 'token_expires_at' => null]);
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    Http::fake(["{$graph}/page-7/conversations*" => Http::response(['data' => []])]);

    $this->service->poll($account, null, CarbonImmutable::parse('2026-09-16'));

    Http::assertSent(fn (Request $request) => str_contains($request->url(), "{$graph}/page-7/conversations") && $request['platform'] === 'instagram');
});

test('a profile Meta did not share is asked again next time', function () {
    Http::fake([
        "{$this->igGraph}/me/conversations*" => Http::response(['data' => []]),
        "{$this->igGraph}/igsid-9*" => Http::sequence()
            ->push(['error' => ['code' => 100, 'message' => 'Unsupported']], 400)
            ->push(['name' => 'Ana Fan', 'username' => 'ana.fan']),
    ]);
    $event = ['sender' => ['id' => 'igsid-9'], 'recipient' => ['id' => 'ig-me'], 'timestamp' => 1760608800000, 'message' => ['mid' => 'mid-1', 'text' => 'Hi']];

    expect($this->service->parseEvent($this->instagram, $event)->subject->participantName)->toBeNull()
        ->and($this->service->parseEvent($this->instagram, $event)->subject->participantName)->toBe('Ana Fan')
        ->and($this->service->parseEvent($this->instagram, $event)->subject->participantName)->toBe('Ana Fan');
    Http::assertSentCount(3);

});

test('a webhook message whose profile Meta does not share takes the name from the conversation', function () {
    Http::fake([
        "{$this->fbGraph}/page-1/conversations*" => Http::response(['data' => [['participants' => ['data' => [
            ['id' => 'psid-9', 'name' => 'Vanessa Lozzardo', 'email' => 'psid-9@facebook.com'],
            ['id' => 'page-1', 'name' => 'Acme', 'email' => 'page-1@facebook.com'],
        ]]]]]),
        "{$this->fbGraph}/psid-9*" => Http::response(['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Unsupported get request']], 400),
    ]);

    $incoming = $this->service->parseEvent($this->facebook, ['sender' => ['id' => 'psid-9'], 'recipient' => ['id' => 'page-1'], 'timestamp' => 1760608800000, 'message' => ['mid' => 'mid-1', 'text' => 'Olá']]);

    expect($incoming->subject->participantName)->toBe('Vanessa Lozzardo')
        ->and($incoming->authorName)->toBe('Vanessa Lozzardo')
        ->and($incoming->subject->participantAvatarUrl)->toBeNull();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), "{$this->fbGraph}/page-1/conversations")
        && $request['user_id'] === 'psid-9' && $request['platform'] === 'messenger');
});

test('a poll stopped at its page budget resumes where it stopped', function () {
    $page = fn (int $number, ?string $after) => Http::response([
        'data' => [['id' => "conv-{$number}", 'updated_time' => '2026-10-16T'.str_pad((string) (11 - $number), 2, '0', STR_PAD_LEFT).':00:00+0000']],
        'paging' => array_filter(['cursors' => ['after' => $after], 'next' => $after ? "https://next/{$after}" : null]),
    ]);
    $sequence = Http::sequence();
    foreach (range(0, 9) as $number) {
        $sequence->pushResponse($page($number, "p{$number}"));
    }
    $sequence->pushResponse($page(10, null));
    Http::fake([
        "{$this->igGraph}/me/conversations*" => $sequence,
        "{$this->igGraph}/conv-*" => Http::response(['messages' => ['data' => []]]),
    ]);

    $first = $this->service->poll($this->instagram, '2026-10-15T00:00:00+00:00', CarbonImmutable::parse('2026-09-16'));

    expect(json_decode($first->cursor, true))->toEqual(['watermark' => '2026-10-15T00:00:00+00:00', 'after' => 'p9', 'newest' => '2026-10-16T11:00:00+00:00']);

    $second = $this->service->poll($this->instagram, $first->cursor, CarbonImmutable::parse('2026-09-16'));

    expect($second->cursor)->toBe('2026-10-16T11:00:00+00:00');
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/conversations') && str_contains($request->url(), 'after=p9'));
});

test('group threads are listed but never read', function () {
    $member = fn (string $id) => ['id' => $id, 'username' => $id];
    Http::fake([
        "{$this->igGraph}/me/conversations*" => Http::response(['data' => [
            ['id' => 'conv-group', 'updated_time' => '2026-10-16T11:30:00+0000', 'participants' => ['data' => [$member('ig-me'), $member('igsid-1'), $member('igsid-2')]]],
            ['id' => 'conv-one', 'updated_time' => '2026-10-16T11:00:00+0000', 'participants' => ['data' => [$member('ig-me'), $member('igsid-9')]]],
        ]]),
        "{$this->igGraph}/conv-one*" => Http::response(['messages' => ['data' => [['id' => 'mid-1', 'created_time' => '2026-10-16T11:00:00+0000']]]]),
        "{$this->igGraph}/mid-1*" => Http::response(['id' => 'mid-1', 'created_time' => '2026-10-16T11:00:00+0000', 'from' => ['id' => 'igsid-9'], 'to' => ['data' => [['id' => 'ig-me']]], 'message' => 'Hi']),
        "{$this->igGraph}/igsid-9*" => Http::response(['username' => 'ana.fan']),
    ]);

    $result = $this->service->poll($this->instagram, null, CarbonImmutable::parse('2026-09-16'));

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['mid-1']);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/me/conversations') && str_contains((string) $request['fields'], 'participants'));
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'conv-group'));
});

test('messages the account owner turned off are a known unavailable state', function () {
    Exceptions::fake();
    config()->set('trypost.inbox.enabled', true);
    Http::fake([config('trypost.platforms.instagram-facebook.graph_api').'/*' => Http::response(['error' => [
        'message' => '(#200) The account owner has disabled access to instagram direct messages.',
        'type' => 'OAuthException',
        'code' => 200,
        'error_subcode' => 2534041,
    ]], 403)]);
    $account = SocialAccount::factory()->instagramFacebook()->withDirectMessageScopes()->create(['token_expires_at' => null]);

    expect(fn () => $this->service->poll($account, null, CarbonImmutable::parse('2026-09-16')))->toThrow(DirectMessagesUnavailableException::class);

    (new SyncInboxAccount($account->id, ConversationKind::DirectMessage))->handle();

    $state = InboxSyncState::query()->where('social_account_id', $account->id)->sole();
    expect($state->last_error)->toBe(InboxMessage::MESSAGES_UNAVAILABLE)
        ->and($state->consecutive_failures)->toBe(0)
        ->and($state->next_sync_at->toDateTimeString())->toBe(now()->addMinutes(SyncInboxAccount::pollMinutes($account, ConversationKind::DirectMessage))->toDateTimeString());
    Exceptions::assertNothingReported();
});

test('a large inbox stops at the poll budget inside a page and resumes without reading anything twice', function () {
    $conversations = collect(range(1, 30))->map(fn (int $number) => ['id' => "conv-{$number}", 'updated_time' => '2026-10-16T10:00:00+0000']);
    $details = [];
    Http::fake(function (Request $request) use ($conversations, &$details) {
        $this->travel(1)->seconds();
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $node = basename($path);
        $isLastPage = data_get($request->data(), 'after') === 'p2';

        return match (true) {
            str_ends_with($path, '/me/conversations') => Http::response(['data' => $isLastPage ? $conversations->slice(25)->values()->all() : $conversations->take(25)->all(), 'paging' => $isLastPage ? [] : ['cursors' => ['after' => 'p2'], 'next' => 'https://next/p2']]),
            str_starts_with($node, 'conv-') => Http::response(['messages' => ['data' => collect(range(1, 5))->map(fn (int $index) => ['id' => 'mid-'.substr($node, 5)."-{$index}", 'created_time' => '2026-10-16T10:00:00+0000'])->all()]]),
            str_starts_with($node, 'igsid-') => Http::response(['name' => 'Fan', 'username' => $node]),
            default => (function () use ($node, &$details) {
                $details[] = $node;

                return Http::response(['id' => $node, 'created_time' => '2026-10-16T10:00:00+0000', 'from' => ['id' => 'igsid-'.explode('-', $node)[1]], 'to' => ['data' => [['id' => 'ig-me']]], 'message' => "Hi {$node}"]);
            })(),
        };
    });

    $cursor = null;
    $read = [];
    $runs = 0;

    do {
        app()->forgetScopedInstances();
        $startedAt = now();
        $result = $this->service->poll($this->instagram, $cursor, CarbonImmutable::parse('2026-09-16'));
        $cursor = $result->cursor;
        $read = [...$read, ...collect($result->messages)->pluck('platformMessageId')->all()];
        $runs++;

        expect((int) $startedAt->diffInSeconds(now()))->toBeLessThanOrEqual((int) config('trypost.inbox.poll_budget_seconds') + 2);
    } while ($result->partial && $runs < 20);

    expect($result->partial)->toBeFalse()
        ->and($runs)->toBeGreaterThan(2)
        ->and($read)->toHaveCount(150)
        ->and(array_unique($read))->toHaveCount(150)
        ->and($details)->toHaveCount(150)
        ->and($cursor)->toBe('2026-10-16T10:00:00+00:00');
});
