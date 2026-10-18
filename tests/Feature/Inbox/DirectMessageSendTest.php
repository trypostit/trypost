<?php

declare(strict_types=1);

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\RetryInboxReply;
use App\Actions\Inbox\SendInboxDirectMessage;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Inbox\ReplyWindowClosedException;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo('2026-10-16 12:00:00');
    $this->driver = fakeDirectMessageDriver();
    $this->account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
    $this->inbound = UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    $this->conversation = InboxConversation::query()->sole();
    $this->user = $this->account->workspace->owner;
});

test('sending stores a sending row and queues it', function () {
    Queue::fake();

    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, '  Thanks for writing!  ');

    expect($message->direction)->toBe(MessageDirection::Outbound)
        ->and($message->delivery_status)->toBe(DeliveryStatus::Sending)
        ->and($message->body)->toBe('Thanks for writing!')
        ->and($message->parent_id)->toBeNull()
        ->and($message->root_id)->toBeNull()
        ->and($message->sent_by_user_id)->toBe($this->user->id);
    Queue::assertPushed(SendInboxReply::class, fn (SendInboxReply $job) => $job->messageId === $message->id);
});

test('the job delivers the DM and answers the conversation', function () {
    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, 'Thanks!');

    $message->refresh();

    expect($this->driver->sent)->toBe([['conversation' => $this->conversation->id, 'text' => 'Thanks!']])
        ->and($message->delivery_status)->toBe(DeliveryStatus::Sent)
        ->and($message->platform_message_id)->toBe('mid-1')
        ->and($this->inbound->fresh()->answered_at)->not->toBeNull()
        ->and($this->conversation->fresh()->open_count)->toBe(0);
});

test('a message after the window is refused', function () {
    $this->travelTo('2026-10-17 10:00:01');

    expect(fn () => SendInboxDirectMessage::execute($this->conversation, $this->user, 'Late'))
        ->toThrow(ValidationException::class, "You can't answer @ana.fan right now. Instagram only allows answers within 24 hours of their last message");
    expect(InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->exists())->toBeFalse();
});

test('a network without a window always accepts a message', function () {
    fakeDirectMessageDriver(windowHours: null, unit: ReplyLengthUnit::Characters);
    $this->conversation->forceFill(['reply_window_ends_at' => null])->save();
    $this->travelTo('2026-12-01 00:00:00');
    Queue::fake();

    expect(SendInboxDirectMessage::execute($this->conversation, $this->user, 'Hello')->delivery_status)->toBe(DeliveryStatus::Sending);
});

test('blank text and text past the limit are refused', function (string $text) {
    expect(fn () => SendInboxDirectMessage::execute($this->conversation, $this->user, $text))
        ->toThrow(ValidationException::class);
})->with([
    'blank' => ['   '],
    'over 1000 bytes in under 1000 characters' => [str_repeat('😀', 300)],
]);

test('only a DM conversation accepts a message', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $comments = InboxConversation::query()->where('kind', 'comment')->sole();

    expect(fn () => SendInboxDirectMessage::execute($comments, $this->user, 'Hi'))
        ->toThrow(ValidationException::class);
});

test('the job does not send once the window has closed', function () {
    Queue::fake();
    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, 'Thanks!');
    $this->travelTo('2026-10-17 10:00:01');

    (new SendInboxReply($message->id))->handle();

    expect($this->driver->sent)->toBe([])
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->fresh()->delivery_error)->toBe(InboxMessage::WINDOW_CLOSED);
});

test('a failed DM cannot be retried after the window', function () {
    Queue::fake();
    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, 'Thanks!');
    $message->forceFill(['delivery_status' => DeliveryStatus::Failed, 'delivery_error' => InboxMessage::REPLY_FAILED])->save();
    $this->travelTo('2026-10-17 10:00:01');

    expect(fn () => RetryInboxReply::execute($message))->toThrow(ValidationException::class);
});

test('an echo that lands first is merged', function () {
    Queue::fake();
    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, 'Thanks!');
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('mid-1', MessageDirection::Outbound, '2026-10-16 12:00:00'));

    (new SendInboxReply($message->id))->handle();

    $outbound = InboxMessage::query()->where('direction', MessageDirection::Outbound->value)->sole();

    expect($outbound->platform_message_id)->toBe('mid-1')
        ->and($outbound->sent_by_user_id)->toBe($this->user->id)
        ->and($outbound->body)->toBe('Thanks!');
});

test('a DM is never answered as a comment reply', function () {
    expect(fn () => ReplyToInboxMessage::execute($this->inbound, $this->user, 'Hi'))
        ->toThrow(ValidationException::class);
});

test('a conversation the person never wrote in cannot be messaged on a network with a window', function () {
    Queue::fake();
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('echo-1', MessageDirection::Outbound, '2026-10-16 11:00:00', participant: 'igsid-echo'));
    $echoOnly = InboxConversation::query()->where('platform_conversation_id', 'igsid-echo')->sole();

    expect($echoOnly->isReplyWindowOpen())->toBeFalse()
        ->and(fn () => SendInboxDirectMessage::execute($echoOnly, $this->user, 'Hi'))->toThrow(ReplyWindowClosedException::class);

    $queued = InboxMessage::query()->forceCreate([
        'inbox_conversation_id' => $echoOnly->id,
        'social_account_id' => $this->account->id,
        'direction' => MessageDirection::Outbound,
        'delivery_status' => DeliveryStatus::Sending,
        'body' => 'Hi',
        'platform_created_at' => now(),
    ]);

    (new SendInboxReply($queued->id))->handle();

    expect($this->driver->sent)->toBe([])
        ->and($queued->fresh()->delivery_error)->toBe(InboxMessage::WINDOW_CLOSED);
});

test('the job does not send once the inbox is turned off, and says so', function () {
    Queue::fake();
    $message = SendInboxDirectMessage::execute($this->conversation, $this->user, 'Thanks!');
    config()->set('trypost.inbox.enabled', false);
    Http::fake();

    (new SendInboxReply($message->id))->handle();

    Http::assertNothingSent();
    expect($this->driver->sent)->toBe([])
        ->and($message->fresh()->delivery_status)->toBe(DeliveryStatus::Failed)
        ->and($message->fresh()->delivery_error)->toBe(InboxMessage::MESSAGES_UNAVAILABLE);
});

test('a Meta conversation stops taking messages 24 hours after the person wrote', function () {
    Queue::fake();
    app()->forgetInstance(InboxDrivers::class);
    $page = SocialAccount::factory()->facebook()->withDirectMessageScopes()->create();
    UpsertInboxMessage::execute($page, inboxDirectIncoming('fb-1', at: '2026-10-15 06:00:00', participant: 'psid-1'));
    $conversation = InboxConversation::query()->where('social_account_id', $page->id)->sole();

    expect($conversation->isReplyWindowOpen())->toBeFalse()
        ->and($conversation->liveReplyWindowEndsAt()->toIso8601String())->toBe('2026-10-16T06:00:00+00:00')
        ->and(fn () => SendInboxDirectMessage::execute($conversation, $this->user, 'Hi'))->toThrow(ReplyWindowClosedException::class);
});
