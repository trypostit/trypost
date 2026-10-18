<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Exceptions\Social\MastodonPublishException;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\MastodonInboxDriver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Queue::fake([SyncInboxAccount::class]);
    Sleep::fake();
    Exceptions::fake();
    $this->travelTo('2026-10-16 12:00:00');
    $this->account = SocialAccount::factory()->mastodon()->create(['platform_user_id' => '100', 'username' => 'me']);
    $this->instance = $this->account->mastodonInstance();
    $this->since = CarbonImmutable::parse('2026-09-16');
    $this->driver = app(MastodonInboxDriver::class);
});

/**
 * Polls the kind five times, each from the cursor the poll before returned.
 *
 * @return list<string|null>
 */
function pollMastodonFiveTimes(MastodonInboxDriver $driver, SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): array
{
    $cursors = [];

    foreach (range(1, 5) as $poll) {
        $cursor = $driver->poll($account, $kind, $cursor, $since)->cursor;
        $cursors[] = $cursor;
    }

    return $cursors;
}

test('a thread that keeps failing is dropped from the comment backlog after five polls and reported once', function () {
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/broken/context" => Http::response(['error' => 'Unprocessable'], 422),
    ]);

    $cursors = pollMastodonFiveTimes($this->driver, $this->account, ConversationKind::Comment, json_encode(['notifications' => null, 'statuses' => null, 'backlog' => ['broken']]), $this->since);

    expect(json_decode((string) $cursors[3], true)['backlog'])->toBe(['broken'])
        ->and(json_decode((string) $cursors[3], true)['attempts'])->toBe(['broken' => 4])
        ->and(json_decode((string) $cursors[4], true)['backlog'])->toBe([]);
    expect(collect(Exceptions::reported())->whereInstanceOf(MastodonPublishException::class))->toHaveCount(1);
});

test('a mention that keeps failing is dropped from the mention backlog after five polls', function () {
    Http::fake([
        "{$this->instance}/api/v1/notifications*" => Http::response([]),
        "{$this->instance}/api/v1/accounts/100/statuses*" => Http::response([]),
        "{$this->instance}/api/v1/statuses/broken" => Http::response(['error' => 'down'], 500),
    ]);

    $cursors = pollMastodonFiveTimes($this->driver, $this->account, ConversationKind::Mention, json_encode(['notifications' => null, 'statuses' => null, 'backlog' => ['broken']]), $this->since);

    expect(json_decode((string) $cursors[3], true)['backlog'])->toBe(['broken'])
        ->and(json_decode((string) $cursors[4], true)['backlog'])->toBe([]);
    expect(collect(Exceptions::reported())->whereInstanceOf(MastodonPublishException::class))->toHaveCount(1);
});

test('a direct conversation that keeps failing is passed after five polls', function () {
    Http::fake([
        "{$this->instance}/api/v1/conversations*" => Http::response([
            ['id' => 'c-1', 'accounts' => [['id' => '200', 'acct' => 'ana']], 'last_status' => ['id' => 's-1', 'created_at' => '2026-10-16T11:00:00.000Z', 'visibility' => 'direct']],
        ]),
        "{$this->instance}/api/v1/statuses/s-1/context" => Http::response(['error' => 'down'], 500),
    ]);

    $cursors = pollMastodonFiveTimes($this->driver, $this->account, ConversationKind::DirectMessage, null, $this->since);

    expect(json_decode((string) $cursors[3], true)['attempts'])->toBe(['c-1' => 4])
        ->and($cursors[4])->toBe('2026-10-16T11:00:00+00:00');
    expect(collect(Exceptions::reported())->whereInstanceOf(MastodonPublishException::class))->toHaveCount(1);
});
