<?php

declare(strict_types=1);

use App\Dto\Inbox\PollResult;
use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SubscribeInboxWebhooks;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\Drivers\YouTubeInboxDriver;
use App\Services\Inbox\Meta\MetaDirectMessages;
use App\Services\Inbox\PollBudget;
use Carbon\CarbonImmutable;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake([SyncInboxAccount::class, SubscribeInboxWebhooks::class]);
});

function spendInboxPollBudget(): void
{
    app()->instance(PollBudget::class, new PollBudget(CarbonImmutable::now()->subSecond()));
}

test('a facebook poll that spends its budget keeps what it read and resumes after the last post it finished', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $account = SocialAccount::factory()->facebook()->withInboxScopes()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Http::fake([
        "{$graph}/page-1/published_posts*" => Http::response(['data' => [
            ['id' => 'page-1_post-2', 'message' => 'Newer', 'created_time' => now()->subDay()->toIso8601String()],
            ['id' => 'page-1_post-1', 'message' => 'Older', 'created_time' => now()->subDays(2)->toIso8601String()],
        ]]),
        "{$graph}/page-1_post-2/comments*" => Http::response(['data' => [['id' => 'c2', 'message' => 'Hi', 'from' => ['id' => 'fan'], 'created_time' => now()->subHour()->toIso8601String()]]]),
        "{$graph}/page-1_post-1/comments*" => Http::response(['data' => [['id' => 'c1', 'message' => 'Yo', 'from' => ['id' => 'fan'], 'created_time' => now()->subHours(2)->toIso8601String()]]]),
    ]);
    spendInboxPollBudget();
    $driver = app(FacebookInboxDriver::class);

    $first = $driver->poll($account, ConversationKind::Comment, null, now()->subDays(30)->toImmutable());
    $second = $driver->poll($account, ConversationKind::Comment, $first->cursor, now()->subDays(30)->toImmutable());

    expect(collect($first->messages)->pluck('platformMessageId')->all())->toBe(['c2'])
        ->and($first->partial)->toBeTrue()
        ->and(collect($second->messages)->pluck('platformMessageId')->all())->toBe(['c1'])
        ->and($second->partial)->toBeFalse();
});

test('a meta direct message poll that spends its budget resumes at the next conversation page', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    $account = SocialAccount::factory()->facebook()->create(['platform_user_id' => 'page-1', 'token_expires_at' => null]);
    Http::fake([
        "{$graph}/page-1/conversations*" => Http::response([
            'data' => [['id' => 'conv-1', 'updated_time' => now()->subHour()->toIso8601String()]],
            'paging' => ['next' => 'https://next', 'cursors' => ['after' => 'page-2']],
        ]),
        "{$graph}/conv-1*" => Http::response(['messages' => ['data' => []]]),
    ]);
    spendInboxPollBudget();

    $result = app(MetaDirectMessages::class)->poll($account, null, now()->subDays(30)->toImmutable());

    expect($result->partial)->toBeTrue()
        ->and(data_get(json_decode((string) $result->cursor, true), 'after'))->toBe('page-2');
    Http::assertSentCount(2);
});

test('a youtube poll that spends its budget keeps the threads it read and the cursor it had', function () {
    $api = config('trypost.platforms.youtube.data_api');
    $account = SocialAccount::factory()->youtube()->create(['platform_user_id' => 'UCme', 'token_expires_at' => now()->addHour()]);
    $thread = fn (string $id) => ['id' => $id, 'snippet' => ['videoId' => 'v1', 'totalReplyCount' => 0, 'topLevelComment' => ['id' => $id, 'snippet' => [
        'publishedAt' => now()->subHour()->toIso8601String(), 'updatedAt' => now()->subHour()->toIso8601String(), 'textDisplay' => 'Hi', 'authorDisplayName' => '@fan',
    ]]]];
    Http::fake([
        "{$api}/commentThreads*" => Http::response(['items' => [$thread('t1'), $thread('t2')]]),
        "{$api}/videos*" => Http::response(['items' => []]),
    ]);
    spendInboxPollBudget();

    $result = app(YouTubeInboxDriver::class)->poll($account, ConversationKind::Comment, '2026-10-01T00:00:00Z', now()->subDays(30)->toImmutable());

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toBe(['t1'])
        ->and($result->partial)->toBeTrue()
        ->and($result->cursor)->toBe('2026-10-01T00:00:00Z');
});

test('a partial sync stores what it read, comes back at once and finishes its backfill from the cursor', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    Post::factory()->forAccount($account)->published()->create(['platform_post_id' => 'root1']);
    $this->mock(BlueskyInboxDriver::class)->shouldReceive('kinds')->andReturn([ConversationKind::Comment])
        ->shouldReceive('poll')->once()->withArgs(fn ($account, $kind, ?string $cursor): bool => $cursor === null)
        ->andReturn(new PollResult([inboxIncoming('at://fan/c1')], 'resume-here', partial: true))
        ->shouldReceive('poll')->once()->withArgs(fn ($account, $kind, ?string $cursor): bool => $cursor === 'resume-here')
        ->andReturn(new PollResult([], '2026-10-16T12:00:00Z'));

    (new SyncInboxAccount($account->id, ConversationKind::Comment, true))->handle();
    $state = InboxSyncState::query()->sole();

    expect(InboxMessage::query()->count())->toBe(1)
        ->and($state->backfilled_at)->toBeNull()
        ->and($state->next_sync_at->isFuture())->toBeFalse();

    (new SyncInboxAccount($account->id, ConversationKind::Comment, true))->handle();

    expect($state->refresh()->backfilled_at)->not->toBeNull()
        ->and($state->cursor)->toBe('2026-10-16T12:00:00Z');
});

test('a sync killed by its timeout records the failure and backs off', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $state = InboxSyncState::factory()->create(['social_account_id' => $account->id, 'kind' => ConversationKind::Comment, 'next_sync_at' => null]);

    (new SyncInboxAccount($account->id, ConversationKind::Comment))->failed(new TimeoutExceededException('App\Jobs\Inbox\SyncInboxAccount has timed out.'));

    expect($state->refresh()->consecutive_failures)->toBe(1)
        ->and($state->last_error)->not->toBeNull()
        ->and($state->next_sync_at->isFuture())->toBeTrue();
});

test('the sync job outlives its poll budget and the inbox supervisor outlives the job', function () {
    expect((new SyncInboxAccount('id', ConversationKind::Comment))->timeout)->toBeGreaterThan((int) config('trypost.inbox.poll_budget_seconds') + 2 * (int) config('trypost.inbox.http_timeout_seconds'))
        ->and(config('horizon.defaults.inbox.timeout'))->toBeGreaterThan((new SyncInboxAccount('id', ConversationKind::Comment))->timeout);
});

test('inbox requests give up sooner than publishing requests', function () {
    $timeout = (fn (): mixed => $this->socialHttp()->getOptions()['timeout'])->call(app(BlueskyInboxDriver::class));

    expect($timeout)->toBe((int) config('trypost.inbox.http_timeout_seconds'))
        ->and($timeout)->toBeLessThan(120);
});

test('a send keeps the publishing timeout while polls keep the short one', function () {
    $driver = app(BlueskyInboxDriver::class);
    $timeout = fn ($instance): mixed => (fn (): mixed => $this->socialHttp()->getOptions()['timeout'])->call($instance);

    expect($timeout($driver->forSending()))->toBe(120)
        ->and($timeout($driver))->toBe((int) config('trypost.inbox.http_timeout_seconds'));
});
