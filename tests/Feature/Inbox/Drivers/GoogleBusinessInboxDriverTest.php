<?php

declare(strict_types=1);

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewReplyState;
use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Drivers\GoogleBusinessInboxDriver;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->account = SocialAccount::factory()->googleBusiness()->create(['token_expires_at' => now()->addHour()]);
    $this->api = config('trypost.platforms.google_business.reviews_api');
    $this->location = 'accounts/123456789/locations/987654321';
    $this->since = CarbonImmutable::parse('2026-09-16');
});

function googleReview(string $id, string $updatedAt, ?array $reply = null, string $stars = 'FOUR'): array
{
    return array_filter([
        'name' => "accounts/123456789/locations/987654321/reviews/{$id}",
        'reviewId' => $id,
        'reviewer' => ['displayName' => 'Ana Souza', 'profilePhotoUrl' => 'https://lh3.googleusercontent.com/ana.jpg', 'isAnonymous' => false],
        'starRating' => $stars,
        'comment' => 'Great coffee',
        'createTime' => '2026-10-15T10:00:00Z',
        'updateTime' => $updatedAt,
        'reviewReply' => $reply,
        'reviewReplyUrl' => "https://business.google.com/reviews/reply/{$id}",
    ]);
}

test('poll maps a review and its reply and stops at the watermark', function () {
    Http::fake([
        "{$this->api}/{$this->location}/reviews*" => Http::response([
            'reviews' => [
                googleReview('r2', '2026-10-16T10:00:00Z', ['comment' => 'Thank you!', 'updateTime' => '2026-10-16T11:00:00Z', 'reviewReplyState' => 'APPROVED']),
                googleReview('r1', '2026-10-10T10:00:00Z'),
            ],
            'nextPageToken' => 'next',
        ]),
    ]);

    $result = app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    expect($result->messages)->toHaveCount(2)
        ->and($result->messages[0]->kind)->toBe(ConversationKind::Review)
        ->and($result->messages[0]->rating)->toBe(4)
        ->and($result->messages[0]->direction)->toBe(MessageDirection::Inbound)
        ->and($result->messages[0]->subject->participantName)->toBe('Ana Souza')
        ->and($result->messages[0]->subject->text)->toBe('Great coffee')
        ->and($result->messages[1]->platformMessageId)->toBe("{$this->location}/reviews/r2/reply")
        ->and($result->messages[1]->platformParentId)->toBe("{$this->location}/reviews/r2")
        ->and($result->messages[1]->direction)->toBe(MessageDirection::Outbound)
        ->and($result->messages[1]->reviewReplyState)->toBe(ReviewReplyState::Approved)
        ->and($result->cursor)->toBe('2026-10-16T10:00:00Z');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'orderBy=updateTime') && str_contains($request->url(), 'pageSize=50'));
});

test('a pending reply is rechecked until google decides', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Review]);
    InboxMessage::factory()->outbound()->for($conversation, 'conversation')->create([
        'platform_message_id' => "{$this->location}/reviews/r1/reply",
        'platform_message_hash' => InboxConversation::hashOf("{$this->location}/reviews/r1/reply"),
        'review_reply_state' => ReviewReplyState::Pending,
    ]);
    Http::fake([
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(googleReview('r1', '2026-10-01T10:00:00Z', [
            'comment' => 'Thanks', 'updateTime' => '2026-10-01T11:00:00Z', 'reviewReplyState' => 'REJECTED', 'policyViolation' => 'OBSCENITY_AND_PROFANITY',
        ])),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);

    $result = app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    $reply = collect($result->messages)->firstWhere('direction', MessageDirection::Outbound);
    expect($reply->reviewReplyState)->toBe(ReviewReplyState::Rejected)
        ->and($reply->meta)->toEqual(['policy_violation' => 'OBSCENITY_AND_PROFANITY']);
});

test('an anonymous or rating-only review still maps', function () {
    $review = googleReview('r3', '2026-10-16T10:00:00Z', stars: 'FIVE');
    unset($review['comment']);
    $review['reviewer'] = ['isAnonymous' => true];
    Http::fake(["{$this->api}/*" => Http::response(['reviews' => [$review]])]);

    $result = app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, null, $this->since);

    expect($result->messages[0]->body)->toBeNull()
        ->and($result->messages[0]->authorName)->toBeNull()
        ->and($result->messages[0]->rating)->toBe(5);
});

test('a reply puts the review reply and comes back pending', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Review]);
    $review = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => "{$this->location}/reviews/r1",
        'platform_message_hash' => InboxConversation::hashOf("{$this->location}/reviews/r1"),
    ]);
    Http::fake(["{$this->api}/{$this->location}/reviews/r1/reply" => Http::response(['comment' => 'Thanks!', 'updateTime' => '2026-10-16T12:00:00Z', 'reviewReplyState' => 'PENDING'])]);

    $sent = app(GoogleBusinessInboxDriver::class)->reply($this->account, $review, 'Thanks!');

    expect($sent->platformMessageId)->toBe("{$this->location}/reviews/r1/reply")
        ->and($sent->reviewReplyState)->toBe(ReviewReplyState::Pending);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request['comment'] === 'Thanks!');
});

test('a 401 after a live verify keeps the channel connected', function () {
    $this->mock(ConnectionVerifier::class)->shouldReceive('verify')->once()->andReturnTrue();
    Http::fake(["{$this->api}/*" => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)]);

    expect(fn () => app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, null, $this->since))
        ->toThrow(PlatformUnavailableException::class);
    expect($this->account->fresh()->status)->toBe(Status::Connected);
});

test('a review reply is measured in bytes', function () {
    Queue::fake();
    config()->set('trypost.inbox.enabled', true);
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Review]);
    $review = InboxMessage::factory()->for($conversation, 'conversation')->create();
    $user = $this->account->workspace->owner;

    expect(fn () => ReplyToInboxMessage::execute($review, $user, str_repeat('😀', 1025)))->toThrow(ValidationException::class);
    expect(ReplyToInboxMessage::execute($review, $user, str_repeat('😀', 1024))->body)->toBe(str_repeat('😀', 1024));
});

function pendingGoogleReply(SocialAccount $account, string $id, ?string $updatedAt = null): InboxMessage
{
    $location = 'accounts/123456789/locations/987654321';
    $conversation = InboxConversation::factory()->create(['social_account_id' => $account->id, 'workspace_id' => $account->workspace_id, 'kind' => ConversationKind::Review]);
    $review = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'social_account_id' => $account->id,
        'platform_message_id' => "{$location}/reviews/{$id}",
        'platform_message_hash' => InboxConversation::hashOf("{$location}/reviews/{$id}"),
    ]);

    $reply = InboxMessage::factory()->outbound()->for($conversation, 'conversation')->create([
        'social_account_id' => $account->id,
        'parent_id' => $review->id,
        'root_id' => $review->id,
        'platform_message_id' => "{$location}/reviews/{$id}/reply",
        'platform_message_hash' => InboxConversation::hashOf("{$location}/reviews/{$id}/reply"),
        'review_reply_state' => ReviewReplyState::Pending,
    ]);

    if (filled($updatedAt)) {
        $reply->forceFill(['updated_at' => $updatedAt])->saveQuietly();
    }

    return $reply;
}

test('a review Google answers 404 for does not fail the poll and stays in the inbox', function () {
    $gone = pendingGoogleReply($this->account, 'gone');
    pendingGoogleReply($this->account, 'r1');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/gone" => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404),
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(googleReview('r1', '2026-10-01T10:00:00Z', [
            'comment' => 'Thanks', 'updateTime' => '2026-10-01T11:00:00Z', 'reviewReplyState' => 'APPROVED',
        ])),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);

    $result = app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    expect(collect($result->messages)->pluck('platformMessageId')->all())->toContain("{$this->location}/reviews/r1/reply")
        ->and($gone->fresh()->removed_at)->toBeNull()
        ->and(InboxMessage::query()->whereKey($gone->root_id)->value('removed_at'))->toBeNull();
});

test('a pending reply Google does not show yet stays pending, never removed', function () {
    $reply = pendingGoogleReply($this->account, 'r1');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(googleReview('r1', '2026-10-01T10:00:00Z')),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);

    app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    expect($reply->fresh()->review_reply_state)->toBe(ReviewReplyState::Pending)
        ->and($reply->fresh()->removed_at)->toBeNull();
});

test('pending replies are rechecked oldest first so none waits forever', function () {
    pendingGoogleReply($this->account, 'newest', now()->subMinute()->toDateTimeString());
    foreach (range(1, 50) as $i) {
        pendingGoogleReply($this->account, "old{$i}", now()->subDays(2)->addMinutes($i)->toDateTimeString());
    }
    Http::fake([
        "{$this->api}/{$this->location}/reviews/old*" => fn (Request $request) => Http::response(googleReview(str($request->url())->afterLast('/')->toString(), '2026-10-01T10:00:00Z', [
            'comment' => 'Thanks', 'updateTime' => '2026-10-01T11:00:00Z', 'reviewReplyState' => 'PENDING',
        ])),
        "{$this->api}/{$this->location}/reviews/newest" => Http::response(googleReview('newest', '2026-10-01T10:00:00Z', [
            'comment' => 'Thanks', 'updateTime' => '2026-10-01T11:00:00Z', 'reviewReplyState' => 'APPROVED',
        ])),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);
    $driver = app(GoogleBusinessInboxDriver::class);

    $driver->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/reviews/newest'));

    $this->travel(1)->minutes();
    $driver->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/reviews/newest'));
});

test('a 404 without a status removes nothing either', function () {
    $gone = pendingGoogleReply($this->account, 'gone');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/gone" => Http::response('<html>Not found</html>', 404),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);

    app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    expect($gone->fresh()->removed_at)->toBeNull();
});

test('a recheck that fails for another reason is reported and does not fail the poll', function () {
    $reply = pendingGoogleReply($this->account, 'r1');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);
    $reported = [];
    app(ExceptionHandler::class)->reportable(function (GoogleBusinessPublishException $exception) use (&$reported) {
        $reported[] = $exception;

        return false;
    });

    app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    expect($reported)->toHaveCount(1)
        ->and($reply->fresh()->removed_at)->toBeNull();
});

test('a failing recheck is reported with its access token redacted', function () {
    pendingGoogleReply($this->account, 'r1');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'bad access_token=secret-token']], 400),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => []]),
    ]);
    Exceptions::fake();

    app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);

    Exceptions::assertNotReported(fn (Throwable $exception): bool => str_contains($exception->getMessage(), 'secret-token'));
    expect(Exceptions::reported())->not->toBeEmpty();
});

test('a pending reply on an older review is decided by the review list, even when the review cannot be read alone', function () {
    $reply = pendingGoogleReply($this->account, 'r1');
    Http::fake([
        "{$this->api}/{$this->location}/reviews/r1" => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.']], 404),
        "{$this->api}/{$this->location}/reviews*" => Http::response(['reviews' => [googleReview('r1', '2026-10-01T10:00:00Z', [
            'comment' => 'Thank you', 'updateTime' => '2026-10-01T11:00:00Z', 'reviewReplyState' => 'APPROVED',
        ])]]),
    ]);

    $result = app(GoogleBusinessInboxDriver::class)->poll($this->account, ConversationKind::Review, '2026-10-12T00:00:00Z', $this->since);
    collect($result->messages)->each(fn ($message) => UpsertInboxMessage::execute($this->account, $message));

    expect($reply->fresh()->review_reply_state)->toBe(ReviewReplyState::Approved)
        ->and($reply->fresh()->removed_at)->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/reviews/r1'));
});
