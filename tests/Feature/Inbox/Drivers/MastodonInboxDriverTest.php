<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\MastodonInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = SocialAccount::factory()->mastodon()->create(['platform_user_id' => '100', 'username' => 'me']);
    $this->instance = $this->account->mastodonInstance();
    $this->since = CarbonImmutable::parse('2026-09-16');
});

function mastodonStatus(string $id, string $accountId, ?string $inReplyTo, string $visibility = 'public', string $at = '2026-10-16T10:00:00.000Z'): array
{
    return [
        'id' => $id,
        'url' => "https://mastodon.social/@user/{$id}",
        'created_at' => $at,
        'visibility' => $visibility,
        'in_reply_to_id' => $inReplyTo,
        'spoiler_text' => '',
        'content' => "<p>Hello &amp; {$id}</p>",
        'media_attachments' => [],
        'account' => [
            'id' => $accountId,
            'acct' => $accountId === '100' ? 'me' : 'fan@other.social',
            'username' => $accountId === '100' ? 'me' : 'fan',
            'display_name' => $accountId === '100' ? 'Me' : 'Fan',
            'avatar' => 'https://files.mastodon.social/fan.png',
        ],
    ];
}

function mastodonFake(string $instance, array $notifications, array $ownStatuses, array $contexts): void
{
    Http::fake([
        "{$instance}/api/v1/notifications*" => Http::response($notifications),
        "{$instance}/api/v1/accounts/100/statuses*" => Http::response($ownStatuses),
        ...collect($contexts)->mapWithKeys(fn (array $context, string $id): array => ["{$instance}/api/v1/statuses/{$id}/context" => Http::response($context)])->all(),
    ]);
}

test('poll emits the whole tree of a reply on our post', function () {
    $root = mastodonStatus('root', '100', null, at: '2026-10-15T10:00:00.000Z');
    $c1 = mastodonStatus('c1', '200', 'root');
    $c2 = mastodonStatus('c2', '100', 'c1', at: '2026-10-16T11:00:00.000Z');
    $c3 = mastodonStatus('c3', '200', 'c2', at: '2026-10-16T12:00:00.000Z');
    mastodonFake($this->instance, [['id' => 'n9', 'type' => 'mention', 'created_at' => '2026-10-16T12:00:00.000Z', 'status' => $c3]], [], [
        'c3' => ['ancestors' => [$root, $c1, $c2], 'descendants' => []],
        'root' => ['ancestors' => [], 'descendants' => [$c1, $c2, $c3]],
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since);

    $messages = collect($result->messages)->keyBy('platformMessageId');
    expect($messages->keys()->all())->toBe(['c1', 'c2', 'c3'])
        ->and($messages['c1']->platformParentId)->toBeNull()
        ->and($messages['c2']->direction)->toBe(MessageDirection::Outbound)
        ->and($messages['c3']->platformParentId)->toBe('c2')
        ->and($messages['c3']->authorUsername)->toBe('fan@other.social')
        ->and($messages['c3']->body)->toBe('Hello & c3')
        ->and($messages['c3']->meta)->toEqual(['visibility' => 'public'])
        ->and($messages['c3']->subject->platformId)->toBe('root')
        ->and($messages['c3']->subject->text)->toBe('Hello & root')
        ->and($messages['c3']->subject->remoteId)->toBe('root')
        ->and(json_decode((string) $result->cursor, true))->toEqual(['notifications' => 'n9', 'statuses' => null, 'backlog' => []]);
});

test('mentions outside our posts, top-level mentions and direct messages are left out', function () {
    $foreignRoot = mastodonStatus('froot', '300', null);
    $onForeign = mastodonStatus('f1', '200', 'froot');
    $topLevel = mastodonStatus('t1', '200', null);
    $direct = mastodonStatus('d1', '200', 'root', 'direct');
    mastodonFake($this->instance, [
        ['id' => 'n3', 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'status' => $onForeign],
        ['id' => 'n2', 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'status' => $topLevel],
        ['id' => 'n1', 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'status' => $direct],
    ], [], [
        'f1' => ['ancestors' => [$foreignRoot], 'descendants' => []],
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since);

    expect($result->messages)->toBe([]);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/statuses/d1/') || str_contains($request->url(), '/statuses/t1/'));
});

test('a poll asks only for mentions newer than the cursor', function () {
    mastodonFake($this->instance, [], [], []);

    app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, json_encode(['notifications' => 'n5', 'statuses' => 's5']), $this->since);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/notifications')
        && str_contains(urldecode($request->url()), 'types[]=mention')
        && str_contains($request->url(), 'min_id=n5'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/accounts/100/statuses') && str_contains($request->url(), 'min_id=s5'));
});

test('a reply keeps the parent visibility and content warning', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'platform_conversation_id' => 'root']);
    $parent = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => 'c1',
        'platform_message_hash' => InboxConversation::hashOf('c1'),
        'meta' => ['visibility' => 'unlisted', 'spoiler_text' => 'spoilers'],
    ]);
    Http::fake(["{$this->instance}/api/v1/statuses" => Http::response(mastodonStatus('r1', '100', 'c1', 'unlisted'))]);

    $sent = app(MastodonInboxDriver::class)->reply($this->account, $parent, '@fan@other.social thanks');

    expect($sent->platformMessageId)->toBe('r1')
        ->and($sent->meta)->toEqual(['visibility' => 'unlisted']);
    Http::assertSent(fn (Request $request): bool => $request['in_reply_to_id'] === 'c1'
        && $request['visibility'] === 'unlisted'
        && $request['spoiler_text'] === 'spoilers'
        && $request->hasHeader('Idempotency-Key'));
});

test('a dead token surfaces as token expired', function () {
    Http::fake(["{$this->instance}/*" => Http::response(['error' => 'The access token is invalid'], 401)]);

    expect(fn () => app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since))
        ->toThrow(TokenExpiredException::class);
});

test('the next poll starts after the newest notification read, not the first page', function () {
    $firstPage = collect(range(80, 1))->map(fn (int $i): array => ['id' => (string) $i, 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'status' => []])->all();
    $secondPage = collect(range(85, 81))->map(fn (int $i): array => ['id' => (string) $i, 'type' => 'mention', 'created_at' => '2026-10-16T10:00:00.000Z', 'status' => []])->all();
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::sequence()->push($firstPage)->push($secondPage),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, json_encode(['notifications' => '0', 'statuses' => 's5']), $this->since);

    expect(data_get(json_decode((string) $result->cursor, true), 'notifications'))->toBe('85');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/notifications') && str_contains($request->url(), 'min_id=80'));
});

test('a poll reads a capped number of threads and resumes the rest on the next poll', function () {
    $foreignRoot = mastodonStatus('froot', '300', null);
    $statuses = collect(range(1, 70))->map(fn (int $i): array => mastodonStatus("s{$i}", '100', 'froot'))->all();
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::sequence()->push($statuses)->whenEmpty(Http::response([])),
        "{$this->instance}/api/v1/statuses/*/context" => Http::response(['ancestors' => [$foreignRoot], 'descendants' => []]),
    ]);
    $driver = app(MastodonInboxDriver::class);

    $first = $driver->poll($this->account, ConversationKind::Comment, null, $this->since);

    $contextCalls = fn (): int => collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/context'))->count();
    expect($contextCalls())->toBe(MastodonInboxDriver::MAX_CONTEXT_CALLS)
        ->and(data_get(json_decode((string) $first->cursor, true), 'backlog'))->toHaveCount(70 - MastodonInboxDriver::MAX_CONTEXT_CALLS);

    $second = $driver->poll($this->account, ConversationKind::Comment, $first->cursor, $this->since);

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/statuses/s70/context'));
    expect(data_get(json_decode((string) $second->cursor, true), 'backlog'))->toBe([]);
});

test('a rate limit while reading threads keeps what was read and says when to come back', function () {
    $root = mastodonStatus('root', '100', null, at: '2026-10-15T10:00:00.000Z');
    $c1 = mastodonStatus('c1', '200', 'root');
    $c2 = mastodonStatus('c2', '200', 'other');
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([
            ['id' => 'n2', 'type' => 'mention', 'created_at' => '2026-10-16T12:00:00.000Z', 'status' => $c1],
            ['id' => 'n1', 'type' => 'mention', 'created_at' => '2026-10-16T11:00:00.000Z', 'status' => $c2],
        ]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/c1/context" => Http::response(['ancestors' => [$root], 'descendants' => []]),
        "{$this->instance}/api/v1/statuses/root/context" => Http::response(['ancestors' => [], 'descendants' => [$c1]]),
        "{$this->instance}/api/v1/statuses/c2/context" => Http::response(['error' => 'Too many requests'], 429, ['X-RateLimit-Reset' => now()->addMinutes(3)->toIso8601String()]),
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since);

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['c1'])
        ->and($result->retryAt)->not->toBeNull()
        ->and($result->retryAt->isFuture())->toBeTrue()
        ->and(json_decode((string) $result->cursor, true))->toEqual(['notifications' => 'n2', 'statuses' => null, 'backlog' => ['c2']]);
});

test('a backlog thread that is gone is dropped and the poll still advances', function () {
    $root = mastodonStatus('root', '100', null, at: '2026-10-15T10:00:00.000Z');
    $c1 = mastodonStatus('c1', '200', 'root');
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([
            ['id' => 'n2', 'type' => 'mention', 'created_at' => '2026-10-16T12:00:00.000Z', 'status' => $c1],
        ]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/gone/context" => Http::response(['error' => 'Record not found'], 404),
        "{$this->instance}/api/v1/statuses/c1/context" => Http::response(['ancestors' => [$root], 'descendants' => []]),
        "{$this->instance}/api/v1/statuses/root/context" => Http::response(['ancestors' => [], 'descendants' => [$c1]]),
    ]);
    $cursor = json_encode(['notifications' => 'n1', 'statuses' => null, 'backlog' => ['gone']]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, $cursor, $this->since);

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['c1'])
        ->and($result->retryAt)->toBeNull()
        ->and(json_decode((string) $result->cursor, true))->toEqual(['notifications' => 'n2', 'statuses' => null, 'backlog' => []]);
});

test('a server error while reading a thread keeps it in the backlog and the next poll recovers it', function () {
    $root = mastodonStatus('root', '100', null, at: '2026-10-15T10:00:00.000Z');
    $c1 = mastodonStatus('c1', '200', 'root');
    $notifications = [['id' => 'n2', 'type' => 'mention', 'created_at' => '2026-10-16T12:00:00.000Z', 'status' => $c1]];

    foreach ([500, 502, 503, 504] as $status) {
        Http::swap(new HttpFactory);
        Http::fake([
            "{$this->instance}/api/v1/notifications*" => Http::response($notifications),
            "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
            "{$this->instance}/api/v1/statuses/c1/context" => Http::response(['error' => 'down'], $status),
        ]);

        $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, json_encode(['notifications' => 'n1', 'statuses' => null, 'backlog' => []]), $this->since);

        expect($result->messages)->toBe([])
            ->and($result->retryAt)->not->toBeNull()
            ->and((int) round(now()->diffInMinutes($result->retryAt, true)))->toBeLessThanOrEqual(5)
            ->and(json_decode((string) $result->cursor, true)['backlog'])->toBe(['c1']);
    }

    Http::swap(new HttpFactory);
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/c1/context" => Http::response(['ancestors' => [$root], 'descendants' => []]),
        "{$this->instance}/api/v1/statuses/root/context" => Http::response(['ancestors' => [], 'descendants' => [$c1]]),
    ]);

    $next = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, $result->cursor, $this->since);

    expect(collect($next->messages)->pluck('platformMessageId')->all())->toBe(['c1'])
        ->and($next->retryAt)->toBeNull()
        ->and(json_decode((string) $next->cursor, true)['backlog'])->toBe([]);
});

test('a 410 thread is dropped like a 404', function () {
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/gone/context" => Http::response(['error' => 'Gone'], 410),
    ]);

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, json_encode(['notifications' => null, 'statuses' => null, 'backlog' => ['gone']]), $this->since);

    expect($result->retryAt)->toBeNull()
        ->and(json_decode((string) $result->cursor, true)['backlog'])->toBe([]);
});

test('a dead token while reading a thread surfaces as token expired', function () {
    $c1 = mastodonStatus('c1', '200', 'root');
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([['id' => 'n2', 'type' => 'mention', 'created_at' => '2026-10-16T12:00:00.000Z', 'status' => $c1]]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/c1/context" => Http::response(['error' => 'The access token is invalid'], 401),
    ]);

    expect(fn () => app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, null, $this->since))
        ->toThrow(TokenExpiredException::class);
});

test('a comment poll never makes more context calls than its budget', function () {
    $contexts = ['x' => ['ancestors' => [mastodonStatus('foreign', '300', null)], 'descendants' => []]];
    foreach (range(1, 30) as $i) {
        $contexts["c{$i}"] = ['ancestors' => [mastodonStatus("r{$i}", '100', null)], 'descendants' => []];
        $contexts["r{$i}"] = ['ancestors' => [], 'descendants' => []];
    }
    mastodonFake($this->instance, [], [], $contexts);
    $backlog = ['x', ...array_map(fn (int $i): string => "c{$i}", range(1, 30))];

    $result = app(MastodonInboxDriver::class)->poll($this->account, ConversationKind::Comment, json_encode(['notifications' => null, 'statuses' => null, 'backlog' => $backlog]), $this->since);

    expect(collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/context'))->count())->toBeLessThanOrEqual(MastodonInboxDriver::MAX_CONTEXT_CALLS)
        ->and(json_decode((string) $result->cursor, true)['backlog'])->toBe(['c30']);
});
