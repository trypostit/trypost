<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;

test('a conversation has nested messages', function () {
    $conversation = InboxConversation::factory()->create();
    $comment = InboxMessage::factory()->for($conversation, 'conversation')->create();
    $reply = InboxMessage::factory()->for($conversation, 'conversation')->outbound()->create([
        'parent_id' => $comment->id,
        'root_id' => $comment->id,
    ]);

    expect($conversation->kind)->toBe(ConversationKind::Comment)
        ->and($conversation->messages)->toHaveCount(2)
        ->and($reply->parent->is($comment))->toBeTrue()
        ->and($comment->replies->first()->is($reply))->toBeTrue()
        ->and($reply->direction)->toBe(MessageDirection::Outbound);
});

test('open means inbound, not answered, not resolved and not removed', function () {
    $conversation = InboxConversation::factory()->create();
    $open = InboxMessage::factory()->for($conversation, 'conversation')->create();
    InboxMessage::factory()->for($conversation, 'conversation')->create(['answered_at' => now()]);
    InboxMessage::factory()->for($conversation, 'conversation')->create(['resolved_at' => now()]);
    InboxMessage::factory()->for($conversation, 'conversation')->create(['removed_at' => now()]);
    InboxMessage::factory()->for($conversation, 'conversation')->outbound()->create();

    expect(InboxMessage::query()->open()->pluck('id')->all())->toBe([$open->id]);
});

test('deleting the channel deletes its inbox rows', function () {
    $conversation = InboxConversation::factory()->create();
    InboxMessage::factory()->for($conversation, 'conversation')->create();

    $conversation->socialAccount->delete();

    expect(InboxConversation::query()->count())->toBe(0)
        ->and(InboxMessage::query()->count())->toBe(0);
});

test('a bluesky channel always has inbox access', function () {
    expect(SocialAccount::factory()->bluesky()->create()->hasInboxAccess())->toBeTrue();
});
