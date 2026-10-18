<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ThreadsPublishException;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\ThreadsInboxDriver;
use App\Services\Inbox\InboxDrivers;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
    Sleep::fake();
    $this->graph = config('trypost.platforms.threads.graph_api');
    $this->account = SocialAccount::factory()->threads()->withInboxScopes()->create([
        'platform_user_id' => 'threads-user-1',
        'username' => 'brand',
        'token_expires_at' => now()->addDays(30),
    ]);
    $this->driver = app(ThreadsInboxDriver::class);
});

/** @return array<string, mixed> */
function threadsReplyValue(array $overrides = []): array
{
    $value = data_get(json_decode((string) file_get_contents(base_path('tests/fixtures/inbox/threads-reply.json')), true), 'values.value');

    return array_replace_recursive($value, $overrides);
}

function threadsReply(string $id): IncomingMessage
{
    return new IncomingMessage(
        kind: ConversationKind::Comment,
        subject: new IncomingSubject('root-1', 'Root', [], 'https://www.threads.net/@brand/post/root', null, 'root-1'),
        platformMessageId: $id,
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: null,
        authorName: 'fan',
        authorUsername: 'fan',
        authorAvatarUrl: null,
        body: 'Reply',
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-15 10:00:00'),
    );
}

function fakeThreadsQuota(string $graph, int $usage): void
{
    Http::fake([
        "{$graph}/threads-user-1/threads_publishing_limit*" => Http::response(['data' => [['reply_quota_usage' => $usage, 'reply_config' => ['quota_total' => 1000, 'quota_duration' => 86400]]]]),
        "{$graph}/threads-user-1/threads" => Http::response(['id' => 'container-1']),
        "{$graph}/container-1*" => Http::response(['status' => 'FINISHED']),
        "{$graph}/threads-user-1/threads_publish" => Http::response(['id' => 'reply-9']),
        "{$graph}/reply-9*" => Http::response(['permalink' => 'https://www.threads.net/@brand/post/x']),
    ]);
}

test('threads uses the threads driver', function () {
    expect(app(InboxDrivers::class)->for(Platform::Threads))->toBeInstanceOf(ThreadsInboxDriver::class);
});

test('a webhook reply to the post has no parent and only names the post', function () {
    Http::fake();

    $events = $this->driver->webhookEvents($this->account, threadsReplyValue());

    expect($events->messages)->toHaveCount(1)
        ->and($events->messages[0]->platformMessageId)->toBe('reply-1')
        ->and($events->messages[0]->platformParentId)->toBeNull()
        ->and($events->messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($events->messages[0]->authorUsername)->toBe('fan')
        ->and($events->messages[0]->platformUrl)->toBe('https://www.threads.net/@fan/post/Pp')
        ->and($events->messages[0]->platformCreatedAt->equalTo(CarbonImmutable::parse('2026-10-16 10:33:16', 'UTC')))->toBeTrue()
        ->and($events->messages[0]->subject->remoteId)->toBe('root-1')
        ->and($events->messages[0]->subject->text)->toBeNull();
    Http::assertNothingSent();
});

test('a webhook reply to a reply keeps its parent', function () {
    Http::fake();

    expect($this->driver->webhookEvents($this->account, threadsReplyValue(['replied_to' => ['id' => 'reply-0']]))->messages[0]->platformParentId)->toBe('reply-0');
});

test('our own reply from the webhook is outbound', function () {
    Http::fake();

    expect($this->driver->webhookEvents($this->account, threadsReplyValue(['username' => 'brand']))->messages[0]->direction)->toBe(MessageDirection::Outbound);
});

test('a webhook reply on another owner post is ignored', function () {
    Http::preventStrayRequests();

    expect($this->driver->webhookEvents($this->account, threadsReplyValue(['root_post' => ['owner_id' => 'someone-else']]))->messages)->toBe([]);
});

test('a webhook reply on a known post makes no request', function () {
    UpsertInboxMessage::execute($this->account, threadsReply('reply-0'));
    Http::preventStrayRequests();
    Http::fake();

    expect($this->driver->webhookEvents($this->account, threadsReplyValue())->messages)->toHaveCount(1);
    Http::assertNothingSent();
});

test('poll reads the whole conversation of recent posts', function () {
    Http::fake([
        "{$this->graph}/threads-user-1/threads*" => Http::response(['data' => [[
            'id' => 'root-1', 'media_type' => 'TEXT_POST', 'text' => 'Our post',
            'permalink' => 'https://www.threads.net/@brand/post/root', 'timestamp' => now()->subDays(2)->toIso8601String(),
        ]]]),
        "{$this->graph}/root-1/conversation*" => Http::response(['data' => [
            ['id' => 'reply-1', 'text' => 'Nice', 'username' => 'fan', 'profile_picture_url' => 'https://cdn.example/fan.jpg', 'timestamp' => now()->subDay()->toIso8601String(), 'replied_to' => ['id' => 'root-1'], 'root_post' => ['id' => 'root-1'], 'is_reply_owned_by_me' => false],
            ['id' => 'reply-2', 'text' => 'Thanks', 'username' => 'brand', 'timestamp' => now()->subHours(20)->toIso8601String(), 'replied_to' => ['id' => 'reply-1'], 'root_post' => ['id' => 'root-1'], 'is_reply_owned_by_me' => true],
        ]]),
    ]);

    $messages = collect($this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages);

    expect($messages->pluck('platformMessageId')->all())->toBe(['reply-1', 'reply-2'])
        ->and($messages[0]->platformParentId)->toBeNull()
        ->and($messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($messages[0]->authorAvatarUrl)->toBe('https://cdn.example/fan.jpg')
        ->and($messages[1]->platformParentId)->toBe('reply-1')
        ->and($messages[1]->direction)->toBe(MessageDirection::Outbound)
        ->and($messages[0]->subject->text)->toBe('Our post');
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$this->graph}/root-1/conversation")
        && ! array_key_exists('reverse', $request->data())
        && str_contains((string) data_get($request->data(), 'fields'), 'profile_picture_url'));
});

test('a hidden reply is not a removed one', function () {
    Http::fake([
        "{$this->graph}/threads-user-1/threads*" => Http::response(['data' => [[
            'id' => 'root-1', 'media_type' => 'TEXT_POST', 'text' => 'Our post', 'timestamp' => now()->subDays(2)->toIso8601String(),
        ]]]),
        "{$this->graph}/root-1/conversation*" => Http::response(['data' => [
            ['id' => 'reply-1', 'text' => 'Spam', 'username' => 'bot', 'timestamp' => now()->subDay()->toIso8601String(), 'replied_to' => ['id' => 'root-1'], 'hide_status' => 'HIDDEN'],
        ]]),
    ]);

    $messages = $this->driver->poll($this->account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable())->messages;

    expect($messages[0]->removed)->toBeFalse();
});

test('reply checks the quota then publishes a reply container', function () {
    fakeThreadsQuota($this->graph, 3);
    $parent = UpsertInboxMessage::execute($this->account, threadsReply('reply-1'));

    $sent = $this->driver->reply($this->account, $parent, 'Thanks!');

    expect($sent->platformMessageId)->toBe('reply-9')
        ->and($sent->platformUrl)->toBe('https://www.threads.net/@brand/post/x');
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$this->graph}/threads-user-1/threads"
        && $request['reply_to_id'] === 'reply-1'
        && $request['media_type'] === 'TEXT');
});

test('reply refuses when the daily quota is used up', function () {
    fakeThreadsQuota($this->graph, 1000);
    $parent = UpsertInboxMessage::execute($this->account, threadsReply('reply-1'));

    try {
        $this->driver->reply($this->account, $parent, 'Thanks!');
        $this->fail('Expected the quota to refuse the reply.');
    } catch (ThreadsPublishException $exception) {
        expect($exception->isLimit())->toBeTrue();
    }

    Http::assertNotSent(fn (Request $request): bool => $request->url() === "{$this->graph}/threads-user-1/threads");
});

test('subscribing needs no request', function () {
    Http::preventStrayRequests();

    expect($this->driver->subscribeWebhooks($this->account))->toBeTrue()
        ->and($this->driver->prefillsMention())->toBeTrue();
});
