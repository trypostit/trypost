<?php

declare(strict_types=1);

use App\Actions\Inbox\RemoveInboxMessages;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me']);
});

test('removing a top-level comment removes its whole tree and drops the counters', function () {
    $root = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('c2', 'c1'));
    Event::fake([InboxUpdated::class]);

    expect(RemoveInboxMessages::execute($this->account, ['c1']))->toBe(2);

    expect(InboxMessage::query()->whereNull('removed_at')->count())->toBe(0)
        ->and($root->conversation->fresh()->open_count)->toBe(0);
    Event::assertDispatchedTimes(InboxUpdated::class, 1);
});

test('removing a nested reply keeps the rest of the tree', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $reply = UpsertInboxMessage::execute($this->account, inboxIncoming('c2', 'c1'));

    expect(RemoveInboxMessages::execute($this->account, ['c2']))->toBe(1)
        ->and(InboxMessage::query()->whereNull('removed_at')->pluck('platform_message_id')->all())->toBe(['c1'])
        ->and($reply->conversation->fresh()->open_count)->toBe(1);
});

test('removing an unknown id stores nothing and broadcasts nothing', function () {
    Event::fake([InboxUpdated::class]);

    expect(RemoveInboxMessages::execute($this->account, ['never-seen']))->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
    Event::assertNotDispatched(InboxUpdated::class);
});

test('removing twice keeps the first removal time', function () {
    $message = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    RemoveInboxMessages::execute($this->account, ['c1']);
    $first = $message->fresh()->removed_at;

    $this->travel(5)->minutes();
    Event::fake([InboxUpdated::class]);

    expect(RemoveInboxMessages::execute($this->account, ['c1']))->toBe(0)
        ->and($message->fresh()->removed_at->equalTo($first))->toBeTrue();
    Event::assertNotDispatched(InboxUpdated::class);
});

test('removal never crosses into another channel', function () {
    $other = SocialAccount::factory()->bluesky()->create();
    $mine = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $theirs = UpsertInboxMessage::execute($other, inboxIncoming('c1'));

    RemoveInboxMessages::execute($other, ['c1']);

    expect($mine->fresh()->removed_at)->toBeNull()
        ->and($theirs->fresh()->removed_at)->not->toBeNull();
});

test('a removed reply reopens its tree even when a failed or sending reply is still there', function () {
    $root = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    InboxMessage::factory()->outbound()->for($root->conversation, 'conversation')->create([
        'social_account_id' => $this->account->id, 'parent_id' => $root->id, 'root_id' => $root->id,
        'delivery_status' => DeliveryStatus::Failed, 'platform_created_at' => '2026-10-16 11:00:00',
    ]);
    UpsertInboxMessage::execute($this->account, inboxIncoming('r1', 'c1', MessageDirection::Outbound, '2026-10-16 12:00:00'));
    expect($root->fresh()->answered_at)->not->toBeNull();

    RemoveInboxMessages::execute($this->account, ['r1']);

    expect($root->fresh()->answered_at)->toBeNull();
});

test('a removed reply reopens only the messages it alone answered', function () {
    $first = UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('r1', 'c1', MessageDirection::Outbound, '2026-10-16 11:00:00'));
    $second = UpsertInboxMessage::execute($this->account, inboxIncoming('c2', 'r1', at: '2026-10-16 12:00:00'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('r2', 'c2', MessageDirection::Outbound, '2026-10-16 13:00:00'));
    expect($second->fresh()->answered_at)->not->toBeNull();

    RemoveInboxMessages::execute($this->account, ['r2']);

    expect($first->fresh()->answered_at)->not->toBeNull()
        ->and($second->fresh()->answered_at)->toBeNull();
});

test('a removed answer in a direct conversation reopens the messages before it', function () {
    $conversation = InboxConversation::factory()->for($this->account, 'socialAccount')->create(['kind' => ConversationKind::DirectMessage]);
    $inbound = InboxMessage::factory()->for($conversation, 'conversation')->create([
        'social_account_id' => $this->account->id, 'platform_message_id' => 'm1', 'platform_message_hash' => InboxConversation::hashOf('m1'),
        'platform_created_at' => '2026-10-16 10:00:00', 'answered_at' => '2026-10-16 11:00:00',
    ]);
    InboxMessage::factory()->outbound()->for($conversation, 'conversation')->create([
        'social_account_id' => $this->account->id, 'platform_message_id' => 'm2', 'platform_message_hash' => InboxConversation::hashOf('m2'),
        'platform_created_at' => '2026-10-16 11:00:00', 'delivery_status' => DeliveryStatus::Sent,
    ]);

    RemoveInboxMessages::execute($this->account, ['m2']);

    expect($inbound->fresh()->answered_at)->toBeNull();
});
