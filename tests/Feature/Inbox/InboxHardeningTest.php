<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\PollResult;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
});

function hardeningIncoming(string $id, array $overrides = []): IncomingMessage
{
    $values = [
        'kind' => ConversationKind::Comment,
        'subject' => new IncomingSubject('post-1', 'Post', [], 'https://example.com/post-1', null, 'post-1'),
        'platformMessageId' => $id,
        'platformParentId' => null,
        'direction' => MessageDirection::Inbound,
        'authorPlatformId' => 'fan-1',
        'authorName' => 'Ana Fan',
        'authorUsername' => 'ana',
        'authorAvatarUrl' => 'https://cdn.example/ana.jpg',
        'body' => 'Hello',
        'media' => [],
        'platformUrl' => 'https://example.com/c1',
        'platformCreatedAt' => CarbonImmutable::parse('2026-10-15 10:00:00'),
        ...$overrides,
    ];

    return new IncomingMessage(...$values);
}

test('a sync whose upsert fails mid-batch keeps the cursor and records a redacted failure', function () {
    Event::fake([InboxUpdated::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    InboxSyncState::query()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment, 'cursor' => 'old', 'backfilled_at' => now()->subDay()]);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andReturn(new PollResult([
            inboxIncoming('c1', at: '2026-10-16 09:00:00'),
            inboxIncoming('c2', at: '2026-10-16 10:00:00'),
        ], 'new'));
    InboxMessage::creating(function (InboxMessage $message): void {
        if ($message->platform_message_id === 'c2') {
            throw new RuntimeException('insert failed for ?access_token=SECRET');
        }
    });
    Exceptions::fake();

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    $state = InboxSyncState::query()->sole();

    expect($state->cursor)->toBe('old')
        ->and($state->consecutive_failures)->toBe(1)
        ->and($state->last_error)->toContain('access_token=[REDACTED]')->not->toContain('SECRET')
        ->and($state->next_sync_at->isFuture())->toBeTrue()
        ->and(collect(Exceptions::reported())->map(fn (Throwable $exception): string => $exception->getMessage())->all())
        ->toBe(['RuntimeException: insert failed for ?access_token=[REDACTED]']);
    Event::assertNotDispatched(InboxUpdated::class);
});

test('a webhook and a poll storing the same new message at once keep one merged row', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $inserted = false;

    DB::listen(function (QueryExecuted $query) use ($account, &$inserted): void {
        if ($inserted || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'inbox_messages') || ! str_contains($query->sql, 'platform_message_hash')) {
            return;
        }

        $inserted = true;
        UpsertInboxMessage::execute($account, hardeningIncoming('c1', ['body' => 'From the webhook', 'platformUrl' => null]));
    });

    $message = UpsertInboxMessage::execute($account, hardeningIncoming('c1', ['body' => 'From the poll']));

    expect($inserted)->toBeTrue()
        ->and(InboxMessage::query()->count())->toBe(1)
        ->and($message->fresh()->body)->toBe('From the poll')
        ->and($message->fresh()->platform_url)->toBe('https://example.com/c1');
});

test('a sync saves the cursor after the messages and then broadcasts', function () {
    Event::fake([InboxUpdated::class]);
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andReturn(new PollResult([inboxIncoming('c1')], 'new'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->sole()->cursor)->toBe('new');
    Event::assertDispatchedTimes(InboxUpdated::class, 1);
});

test('a failure carrying a token never reaches the sync state or the error report', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    Exceptions::fake();
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->andThrow(new RuntimeException('GET https://graph.example/x?access_token=SECRET failed'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->handle();

    expect(InboxSyncState::query()->sole()->last_error)->toContain('access_token=[REDACTED]')->not->toContain('SECRET');
    expect(collect(Exceptions::reported())->map(fn (Throwable $exception): string => $exception->getMessage())->all())
        ->toBe(['RuntimeException: GET https://graph.example/x?access_token=[REDACTED] failed']);
});

test('a subscription failure carrying a token never reaches the sync state or the error report', function () {
    $account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['token_expires_at' => null]);
    Exceptions::fake();
    $this->mock(FacebookInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('subscribeWebhooks')->andThrow(new RuntimeException('POST https://graph.example/x?access_token=SECRET failed'));

    (new SubscribeInboxWebhooks($account->id))->handle();

    expect(InboxSyncState::query()->sole()->last_error)->toContain('access_token=[REDACTED]')->not->toContain('SECRET')
        ->and(collect(Exceptions::reported())->map(fn (Throwable $exception): string => $exception->getMessage())->all())
        ->toBe(['RuntimeException: POST https://graph.example/x?access_token=[REDACTED] failed']);
});

test('a partial delivery never blanks what the stored message already knows', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    UpsertInboxMessage::execute($account, hardeningIncoming('c1'));

    UpsertInboxMessage::execute($account, hardeningIncoming('c1', [
        'authorPlatformId' => null,
        'authorName' => null,
        'authorUsername' => null,
        'authorAvatarUrl' => null,
        'platformUrl' => null,
        'platformCreatedAt' => CarbonImmutable::parse('2026-10-16 08:00:00'),
        'body' => 'Hello, edited',
    ]));

    $message = InboxMessage::query()->sole();

    expect($message->body)->toBe('Hello, edited')
        ->and($message->author_platform_id)->toBe('fan-1')
        ->and($message->author_name)->toBe('Ana Fan')
        ->and($message->author_username)->toBe('ana')
        ->and(InboxMediaSlots::pendingLinkOf(InboxMediaSlots::source($message), 'author_avatar_path'))->toBe('https://cdn.example/ana.jpg')
        ->and($message->platform_url)->toBe('https://example.com/c1')
        ->and($message->platform_created_at->equalTo(CarbonImmutable::parse('2026-10-15 10:00:00')))->toBeTrue();
});

test('a reply sent from the inbox stays outbound when its echo looks inbound', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $message = UpsertInboxMessage::execute($account, hardeningIncoming('reply-9', ['direction' => MessageDirection::Outbound]));
    $message->forceFill(['sent_by_user_id' => $account->workspace->owner->id])->save();

    UpsertInboxMessage::execute($account, hardeningIncoming('reply-9', ['direction' => MessageDirection::Inbound]));

    expect($message->fresh()->direction)->toBe(MessageDirection::Outbound);
});
