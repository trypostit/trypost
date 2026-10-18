<?php

declare(strict_types=1);

use App\Actions\Inbox\RemoveInboxMessages;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewRejectionReason;
use App\Enums\Inbox\ReviewReplyState;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Http\Resources\Api\InboxMessageResource;
use App\Jobs\Inbox\SendInboxReply;
use App\Jobs\Inbox\SyncInboxAccount;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;
use App\Support\Inbox\InboxMediaSlots;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->account = SocialAccount::factory()->googleBusiness()->create(['platform_user_id' => 'locations/987654321']);
});

function inboxReviewIncoming(?ReviewReplyState $replyState = null, string $replyText = 'Thank you!', string $replyAt = '2026-10-15 12:00:00'): array
{
    $subject = new IncomingSubject(
        platformId: 'accounts/1/locations/2/reviews/r1',
        text: 'Great coffee',
        media: [],
        url: 'https://business.google.com/reviews/r1',
        publishedAt: CarbonImmutable::parse('2026-10-15 10:00:00'),
        remoteId: null,
        participantName: 'Ana Souza',
        participantAvatarUrl: 'https://lh3.googleusercontent.com/ana.jpg',
    );

    $review = new IncomingMessage(
        kind: ConversationKind::Review,
        subject: $subject,
        platformMessageId: 'accounts/1/locations/2/reviews/r1',
        platformParentId: null,
        direction: MessageDirection::Inbound,
        authorPlatformId: null,
        authorName: 'Ana Souza',
        authorUsername: null,
        authorAvatarUrl: 'https://lh3.googleusercontent.com/ana.jpg',
        body: 'Great coffee',
        media: [],
        platformUrl: 'https://business.google.com/reviews/r1',
        platformCreatedAt: CarbonImmutable::parse('2026-10-15 10:00:00'),
        rating: 4,
    );

    $reply = $replyState ? new IncomingMessage(
        kind: ConversationKind::Review,
        subject: $subject,
        platformMessageId: 'accounts/1/locations/2/reviews/r1/reply',
        platformParentId: 'accounts/1/locations/2/reviews/r1',
        direction: MessageDirection::Outbound,
        authorPlatformId: 'locations/987654321',
        authorName: 'My Cafe',
        authorUsername: null,
        authorAvatarUrl: null,
        body: $replyText,
        media: [],
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse($replyAt),
        reviewReplyState: $replyState,
    ) : null;

    return [$review, $reply];
}

test('a review stores its reviewer, rating and reply state', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Pending);

    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);

    $conversation = InboxConversation::query()->sole();
    expect($conversation->kind)->toBe(ConversationKind::Review)
        ->and($conversation->rating)->toBe(4)
        ->and($conversation->participant_name)->toBe('Ana Souza')
        ->and(InboxMediaSlots::pendingLinkOf(InboxMediaSlots::source($conversation), 'participant_avatar_path'))->toBe('https://lh3.googleusercontent.com/ana.jpg')
        ->and($conversation->open_count)->toBe(0);

    $stored = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole();
    expect($stored->review_reply_state)->toBe(ReviewReplyState::Pending);
});

test('a rejected review reply reopens the review', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Pending);
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);
    expect(InboxConversation::query()->sole()->open_count)->toBe(0);

    [, $rejected] = inboxReviewIncoming(ReviewReplyState::Rejected);
    UpsertInboxMessage::execute($this->account, $rejected);

    $conversation = InboxConversation::query()->sole();
    expect($conversation->open_count)->toBe(1)
        ->and(InboxMessage::query()->where('direction', MessageDirection::Inbound->value)->sole()->answered_at)->toBeNull();
});

test('replacing a review reply keeps one row with the new text', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Rejected, 'Old reply');
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply)->forceFill(['meta' => ['policy_violation' => 'OBSCENITY_AND_PROFANITY']])->save();
    $parent = InboxMessage::query()->where('direction', MessageDirection::Inbound->value)->sole();

    $pending = InboxMessage::factory()->outbound()->create([
        'inbox_conversation_id' => $parent->inbox_conversation_id,
        'parent_id' => $parent->id,
        'platform_message_id' => null,
        'platform_message_hash' => null,
        'delivery_status' => DeliveryStatus::Sending,
        'sent_by_user_id' => $this->account->workspace->owner->id,
        'body' => 'New reply',
    ]);

    $driver = Mockery::mock(InboxDriver::class);
    $driver->shouldReceive('reply')->once()->andReturn(new SentReply(
        platformMessageId: 'accounts/1/locations/2/reviews/r1/reply',
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 09:00:00'),
        reviewReplyState: ReviewReplyState::Pending,
    ));
    $drivers = Mockery::mock(InboxDrivers::class);
    $drivers->shouldReceive('for')->andReturn($driver);
    app()->instance(InboxDrivers::class, $drivers);

    (new SendInboxReply($pending->id))->handle();

    $outbound = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole();
    expect($outbound->body)->toBe('New reply')
        ->and($outbound->review_reply_state)->toBe(ReviewReplyState::Pending)
        ->and($outbound->sent_by_user_id)->toBe($this->account->workspace->owner->id)
        ->and($outbound->platform_created_at->toDateTimeString())->toBe('2026-10-16 09:00:00')
        ->and($outbound->meta)->not->toHaveKey('policy_violation');
});

test('a review reply replaced on Google takes its new text, state and time on the next read', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Pending, 'Old reply', '2026-10-15 12:00:00');
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);

    [, $replaced] = inboxReviewIncoming(ReviewReplyState::Approved, 'New reply', '2026-10-16 08:30:00');
    UpsertInboxMessage::execute($this->account, $replaced);

    $outbound = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole();
    expect($outbound->body)->toBe('New reply')
        ->and($outbound->review_reply_state)->toBe(ReviewReplyState::Approved)
        ->and($outbound->platform_created_at->toDateTimeString())->toBe('2026-10-16 08:30:00');
});

test('a review keeps the time it was first written when Google reads it again', function () {
    [$review] = inboxReviewIncoming();
    UpsertInboxMessage::execute($this->account, $review);

    $later = new IncomingMessage(...[...get_object_vars($review), 'platformCreatedAt' => CarbonImmutable::parse('2026-10-16 11:00:00')]);
    UpsertInboxMessage::execute($this->account, $later);

    expect(InboxMessage::query()->sole()->platform_created_at->toDateTimeString())->toBe('2026-10-15 10:00:00');
});

test('a refusal with a reset time defers the next sync until then', function () {
    config()->set('trypost.inbox.enabled', true);
    $resetAt = now()->addHours(5)->toImmutable();
    $exception = new YouTubePublishException(userMessage: 'quota', category: ErrorCategory::RateLimit, platformErrorCode: 'quotaExceeded');
    $exception->retryAt = $resetAt;

    $driver = Mockery::mock(InboxDriver::class);
    $driver->shouldReceive('kinds')->andReturn([ConversationKind::Review]);
    $driver->shouldReceive('poll')->andThrow($exception);
    $drivers = Mockery::mock(InboxDrivers::class);
    $drivers->shouldReceive('for')->andReturn($driver);
    $drivers->shouldReceive('kindsFor')->andReturn([ConversationKind::Review]);
    $drivers->shouldReceive('supports')->andReturnTrue();
    app()->instance(InboxDrivers::class, $drivers);

    (new SyncInboxAccount($this->account->id, ConversationKind::Review))->handle();

    expect(InboxSyncState::query()->sole()->next_sync_at->getTimestamp())->toBe($resetAt->getTimestamp());
});

test('the message resource exposes the reply state and the policy violation', function () {
    $message = InboxMessage::factory()->outbound()->create([
        'review_reply_state' => ReviewReplyState::Rejected,
        'meta' => ['policy_violation' => 'OBSCENITY_AND_PROFANITY'],
    ]);

    $json = (new InboxMessageResource($message))->resolve();

    expect($json['review_reply_state'])->toBe('rejected')
        ->and($json['policy_violation'])->toBe('OBSCENITY_AND_PROFANITY');
});

test('a rejected reply explains in the user language why Google refused it', function (?string $violation, string $key) {
    $message = InboxMessage::factory()->outbound()->create([
        'review_reply_state' => ReviewReplyState::Rejected,
        'meta' => array_filter(['policy_violation' => $violation]),
    ]);

    expect((new InboxMessageResource($message))->resolve()['rejection_reason'])->toBe(__("inbox.review.rejection.{$key}"));
})->with([
    'offensive language' => ['OBSCENITY_AND_PROFANITY', 'offensive'],
    'an ad' => ['ADVERTISING_AND_SOLICITATION', 'promotional'],
    'personal information' => ['PERSONAL_INFO', 'personal_info'],
    'a suspended profile' => ['LOCATION_SUSPENDED', 'profile_restricted'],
    'an unverified profile' => ['LOCATION_VERIFICATION_REQUIRED', 'profile_not_verified'],
    'another owner' => ['OWNERSHIP_REVOKED', 'ownership_revoked'],
    'a moderation error' => ['MODERATION_ERROR', 'moderation_error'],
    'a value Google adds later' => ['SOMETHING_NEW', 'policy'],
    'no reason given' => [null, 'policy'],
]);

test('every documented policy violation has a reason', function () {
    $documented = [
        'ACCOUNT_RESTRICTED_AND_SUSPENDED',
        'ACCOUNT_RESTRICTED_AND_EDIT_REJECTED',
        'ADULT_THEMED',
        'ADVERTISING_AND_SOLICITATION',
        'ASSOCIATED_ACCOUNT_SUSPENDED',
        'BUSINESS_INELIGIBLE',
        'CHILD_SAFETY',
        'DANGEROUS',
        'EDITING_RESTRICTION',
        'FAKE_ENGAGEMENT',
        'GENERIC_FRAUD',
        'GENERIC_QUALITY',
        'GENERIC_VANDALISM',
        'GENERIC_VIOLATION',
        'GEOGRAPHIC_LOCATION_CHANGE',
        'GIBBERISH_AND_REPETITIVE',
        'GOOGLE_PAY_SUSPENSION',
        'HARASSMENT',
        'HATE_SPEECH',
        'IDENTITY_CHANGE',
        'ILLEGAL',
        'ILLEGAL_BUSINESS_HOURS_EDIT',
        'IMPERSONATION',
        'LOCATION_DISABLED',
        'LOCATION_SUSPENDED',
        'LOCATION_VERIFICATION_REQUIRED',
        'MISINFORMATION',
        'MISREPRESENTATION',
        'MODERATION_ERROR',
        'OBSCENITY_AND_PROFANITY',
        'OFF_TOPIC',
        'OFFENSIVE',
        'OWNERSHIP_REVOKED',
        'PERSONAL_INFO',
        'PHOTOS_AND_VIDEOS_CRITERIA',
        'PO_BOX',
        'POLICY_VIOLATION_UNSPECIFIED',
        'PRIVACY',
        'REGULATED_GOODS_AND_SERVICES',
        'REPETITIVE',
        'SEXUALLY_EXPLICIT',
        'UNCLEAR',
        'VIOLENCE_AND_GORE',
    ];

    expect($documented)->toHaveCount(43)
        ->and(array_keys(ReviewRejectionReason::GOOGLE_VALUES))->toEqualCanonicalizing($documented);

    foreach ($documented as $violation) {
        expect(ReviewRejectionReason::GOOGLE_VALUES)->toHaveKey($violation);
    }
});

test('fake engagement reads as a policy refusal', function () {
    expect(ReviewRejectionReason::fromGoogle('FAKE_ENGAGEMENT'))->toBe(ReviewRejectionReason::Policy);
});

test('a reply that is not rejected carries no reason', function () {
    $message = InboxMessage::factory()->outbound()->create(['review_reply_state' => ReviewReplyState::Pending]);

    expect((new InboxMessageResource($message))->resolve()['rejection_reason'])->toBeNull();
});

test('a removed review reply reopens the review', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Approved);
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);
    expect(InboxConversation::query()->sole()->open_count)->toBe(0);

    RemoveInboxMessages::execute($this->account, ['accounts/1/locations/2/reviews/r1/reply']);

    expect(InboxConversation::query()->sole()->open_count)->toBe(1)
        ->and(InboxMessage::query()->where('direction', MessageDirection::Inbound->value)->sole()->answered_at)->toBeNull();
});

test('a reply sent again after the old one was removed is visible and answers the review', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Approved);
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);
    RemoveInboxMessages::execute($this->account, ['accounts/1/locations/2/reviews/r1/reply']);
    $parent = InboxMessage::query()->where('direction', MessageDirection::Inbound->value)->sole();

    $pending = InboxMessage::factory()->outbound()->create([
        'inbox_conversation_id' => $parent->inbox_conversation_id,
        'parent_id' => $parent->id,
        'platform_message_id' => null,
        'platform_message_hash' => null,
        'delivery_status' => DeliveryStatus::Sending,
        'sent_by_user_id' => $this->account->workspace->owner->id,
        'body' => 'Second try',
    ]);

    $driver = Mockery::mock(InboxDriver::class);
    $driver->shouldReceive('reply')->once()->andReturn(new SentReply(
        platformMessageId: 'accounts/1/locations/2/reviews/r1/reply',
        platformUrl: null,
        platformCreatedAt: CarbonImmutable::parse('2026-10-16 09:00:00'),
        reviewReplyState: ReviewReplyState::Pending,
    ));
    $drivers = Mockery::mock(InboxDrivers::class);
    $drivers->shouldReceive('for')->andReturn($driver);
    app()->instance(InboxDrivers::class, $drivers);

    (new SendInboxReply($pending->id))->handle();

    $outbound = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole();
    expect($outbound->removed_at)->toBeNull()
        ->and($outbound->body)->toBe('Second try')
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});

test('a reply the next read brings back after a removal is visible again', function () {
    [$review, $reply] = inboxReviewIncoming(ReviewReplyState::Approved);
    UpsertInboxMessage::execute($this->account, $review);
    UpsertInboxMessage::execute($this->account, $reply);
    RemoveInboxMessages::execute($this->account, ['accounts/1/locations/2/reviews/r1/reply']);

    UpsertInboxMessage::execute($this->account, $reply);

    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole()->removed_at)->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});
