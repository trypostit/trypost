<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\MessageLimit;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->driver = fakeDirectMessageDriver();
    $this->account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
});

test('a DM conversation stores its participant and flat messages', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', at: '2026-10-16 10:05:00'));

    $conversation = InboxConversation::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::DirectMessage)
        ->and($conversation->isFlat())->toBeTrue()
        ->and($conversation->participant_name)->toBe('Ana Fan')
        ->and($conversation->participant_username)->toBe('ana.fan')
        ->and($conversation->participant_avatar_path)->toBeNull()
        ->and(InboxMediaSlots::pendingLinkOf(InboxMediaSlots::source($conversation), 'participant_avatar_path'))->toBe('https://cdn.example/ana.jpg')
        ->and($conversation->open_count)->toBe(2)
        ->and($conversation->latestMessage->platform_message_id)->toBe('m2')
        ->and(InboxMessage::query()->whereNotNull('parent_id')->orWhereNotNull('root_id')->exists())->toBeFalse();
});

test('an outbound DM answers every earlier inbound message of the conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', at: '2026-10-16 10:01:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m3', MessageDirection::Outbound, '2026-10-16 10:02:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m4', at: '2026-10-16 10:03:00'));

    $answered = InboxMessage::query()->whereNotNull('answered_at')->pluck('platform_message_id')->sort()->values()->all();

    expect($answered)->toBe(['m1', 'm2'])
        ->and(InboxConversation::query()->sole()->open_count)->toBe(1);
});

test('an outbound DM that arrives before an earlier inbound one still answers it', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m3', MessageDirection::Outbound, '2026-10-16 10:02:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));

    expect(InboxMessage::query()->where('platform_message_id', 'm1')->value('answered_at'))->not->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});

test('a DM still being sent answers nothing yet', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    $conversation = InboxConversation::query()->sole();
    InboxMessage::factory()->for($conversation, 'conversation')->create([
        'social_account_id' => $this->account->id,
        'direction' => MessageDirection::Outbound,
        'delivery_status' => DeliveryStatus::Sending,
        'platform_message_id' => null,
        'platform_created_at' => '2026-10-16 11:00:00',
    ]);

    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', at: '2026-10-16 10:30:00'));

    expect(InboxMessage::query()->where('direction', MessageDirection::Inbound->value)->whereNotNull('answered_at')->exists())->toBeFalse();
});

test('the reply window follows the last inbound message', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', MessageDirection::Outbound, '2026-10-16 12:00:00'));

    expect(InboxConversation::query()->sole()->reply_window_ends_at->toIso8601String())->toBe('2026-10-17T10:00:00+00:00');

    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m3', at: '2026-10-16 15:00:00'));

    expect(InboxConversation::query()->sole()->reply_window_ends_at->toIso8601String())->toBe('2026-10-17T15:00:00+00:00');
});

test('a network without a window leaves it empty', function () {
    fakeDirectMessageDriver(windowHours: null);

    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1'));

    expect(InboxConversation::query()->sole()->reply_window_ends_at)->toBeNull();
});

test('a comment conversation never gets a reply window', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

    expect(InboxConversation::query()->sole()->reply_window_ends_at)->toBeNull();
});

test('a deleted DM leaves the open count', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', removed: true));

    expect(InboxConversation::query()->sole()->open_count)->toBe(0)
        ->and(InboxMessage::query()->sole()->removed_at)->not->toBeNull();
});

test('a message limit measures characters or bytes', function () {
    $emoji = str_repeat('😀', 300);

    expect((new MessageLimit(1000, ReplyLengthUnit::Characters))->fits($emoji))->toBeTrue()
        ->and((new MessageLimit(1000, ReplyLengthUnit::Bytes))->fits($emoji))->toBeFalse()
        ->and((new MessageLimit(1000, ReplyLengthUnit::Bytes))->measure($emoji))->toBe(1200);
});

test('the latest message loads once per conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('a1', at: '2026-10-16 10:00:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('a2', at: '2026-10-16 11:00:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('b1', at: '2026-10-16 09:00:00', participant: 'igsid-2'));

    $latest = InboxConversation::query()->with('latestMessage')->get()
        ->mapWithKeys(fn (InboxConversation $conversation) => [$conversation->platform_conversation_id => $conversation->latestMessage->platform_message_id]);

    expect($latest->all())->toEqual(['igsid-1' => 'a2', 'igsid-2' => 'b1']);
});
