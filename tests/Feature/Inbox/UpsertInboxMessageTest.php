<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Events\InboxUpdated;
use App\Models\AnalyticsPublication;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([InboxUpdated::class]);
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me']);
    $this->root = 'at://did:plc:me/app.bsky.feed.post/root1';
    $this->post = Post::factory()->forAccount($this->account)->published()->create(['platform_post_id' => 'root1']);
});

test('a first comment creates the conversation and counts it open', function () {
    $message = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    $conversation = $message->conversation;
    expect($conversation->kind)->toBe(ConversationKind::Comment)
        ->and($conversation->workspace_id)->toBe($this->account->workspace_id)
        ->and($conversation->post_id)->toBe($this->post->id)
        ->and($conversation->subject_text)->toBeNull()
        ->and($conversation->subject_url)->toBeNull()
        ->and($conversation->open_count)->toBe(1)
        ->and($conversation->message_count)->toBe(1)
        ->and($message->root_id)->toBeNull();
    Event::assertDispatched(InboxUpdated::class);
});

test('upserting the same message twice keeps one row and the same counters', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect(InboxMessage::query()->count())->toBe(1)
        ->and(InboxConversation::query()->sole()->open_count)->toBe(1);
});

test('a reply nests under its parent and keeps the root', function () {
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    $reply = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c2', 'at://fan/c1', at: '2026-10-16 10:05:00'));
    $deeper = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c3', 'at://fan/c2', at: '2026-10-16 10:06:00'));

    expect($reply->parent_id)->toBe($comment->id)
        ->and($reply->root_id)->toBe($comment->id)
        ->and($deeper->root_id)->toBe($comment->id);
});

test('an orphan reply is re-linked when its parent arrives', function () {
    $reply = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c2', 'at://fan/c1', at: '2026-10-16 10:05:00'));
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect($reply->fresh()->parent_id)->toBe($comment->id)
        ->and($reply->fresh()->root_id)->toBe($comment->id);
});

test('an outbound reply answers every earlier inbound message of its tree only', function () {
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    $later = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c9', 'at://fan/c1', at: '2026-10-16 12:00:00'));
    $other = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/x1'));

    UpsertInboxMessage::execute($this->account, inboxIncoming('at://me/r1', 'at://fan/c1', MessageDirection::Outbound, '2026-10-16 11:00:00'));

    expect($comment->fresh()->answered_at)->not->toBeNull()
        ->and($later->fresh()->answered_at)->toBeNull()
        ->and($other->fresh()->answered_at)->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(2);
});

test('the conversation links the known publication', function () {
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->account->workspace_id,
        'social_account_id' => $this->account->id,
        'remote_id' => 'root1',
    ]);

    $message = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect($message->conversation->analytics_publication_id)->toBe($publication->id);
});

test('an out-of-order chain is re-rooted all the way down', function () {
    $c3 = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c3', 'at://fan/c2', at: '2026-10-16 10:06:00'));
    $c2 = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c2', 'at://fan/c1', at: '2026-10-16 10:05:00'));
    $c1 = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect($c1->fresh()->root_id)->toBeNull()
        ->and($c2->fresh()->parent_id)->toBe($c1->id)
        ->and($c2->fresh()->root_id)->toBe($c1->id)
        ->and($c3->fresh()->parent_id)->toBe($c2->id)
        ->and($c3->fresh()->root_id)->toBe($c1->id);
});

test('an outbound reply that arrives before its parent answers it once the parent lands', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://me/r1', 'at://fan/c1', MessageDirection::Outbound, '2026-10-16 11:00:00'));
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect($comment->fresh()->answered_at)->not->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});

test('an inbound message with an earlier time arriving after the reply is answered', function () {
    $comment = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://me/r1', 'at://fan/c1', MessageDirection::Outbound, '2026-10-16 11:00:00'));
    $early = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c5', 'at://fan/c1', at: '2026-10-16 10:30:00'));

    expect($early->fresh()->answered_at)->not->toBeNull()
        ->and($comment->fresh()->answered_at)->not->toBeNull()
        ->and(InboxConversation::query()->sole()->open_count)->toBe(0);
});

function inboxIncomingWith(string $id, array $overrides = [], array $subject = []): IncomingMessage
{
    $base = inboxIncoming($id);

    return new IncomingMessage(...array_merge([
        'kind' => $base->kind,
        'subject' => new IncomingSubject(...array_merge([
            'platformId' => $base->subject->platformId,
            'text' => $base->subject->text,
            'media' => $base->subject->media,
            'url' => $base->subject->url,
            'publishedAt' => $base->subject->publishedAt,
            'remoteId' => $base->subject->remoteId,
        ], $subject)),
        'platformMessageId' => $id,
        'platformParentId' => null,
        'direction' => MessageDirection::Inbound,
        'authorPlatformId' => 'did:plc:fan',
        'authorName' => 'Fan',
        'authorUsername' => 'fan.bsky.social',
        'authorAvatarUrl' => null,
        'body' => 'Nice post',
        'media' => [],
        'platformUrl' => null,
        'platformCreatedAt' => $base->platformCreatedAt,
    ], $overrides));
}

test('a message the network removed is stamped removed and leaves the counts', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c1', ['removed' => true]));

    $conversation = InboxConversation::query()->sole();
    expect(InboxMessage::query()->sole()->removed_at)->not->toBeNull()
        ->and($conversation->open_count)->toBe(0)
        ->and($conversation->inbound_count)->toBe(0);

    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect(InboxMessage::query()->sole()->removed_at)->not->toBeNull();
});

test('the inbound count leaves out our replies', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://me/r1', 'at://fan/c1', MessageDirection::Outbound, '2026-10-16 11:00:00'));

    $conversation = InboxConversation::query()->sole();
    expect($conversation->message_count)->toBe(2)
        ->and($conversation->inbound_count)->toBe(1);
});

test('a later mention fills in the post snapshot the first one lacked', function () {
    UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c1', ['kind' => ConversationKind::Mention], ['text' => null, 'url' => null, 'publishedAt' => null, 'remoteId' => null, 'platformId' => 'at://did:plc:fan/app.bsky.feed.post/m1']));
    UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c2', ['kind' => ConversationKind::Mention], ['text' => 'My post, edited', 'media' => [['type' => 'image', 'url' => 'https://cdn/a.jpg']], 'remoteId' => null, 'platformId' => 'at://did:plc:fan/app.bsky.feed.post/m1']));

    $conversation = InboxConversation::query()->sole();
    expect($conversation->subject_text)->toBe('My post, edited')
        ->and($conversation->subject_media)->toEqual([['type' => 'image']])
        ->and(InboxMediaSlots::pendingLinkOf(InboxMediaSlots::source($conversation), 'subject_media.0.path'))->toBe('https://cdn/a.jpg')
        ->and($conversation->subject_url)->toBe('https://bsky.app/profile/me/post/root1')
        ->and($conversation->subject_published_at)->not->toBeNull();
});

test('a mention without the post snapshot keeps the stored one', function () {
    UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c1', ['kind' => ConversationKind::Mention]));
    UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c2', ['kind' => ConversationKind::Mention], ['text' => null, 'url' => null, 'publishedAt' => null]));

    expect(InboxConversation::query()->sole()->subject_text)->toBe('My post');
});

test('an upsert without broadcast stays quiet', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'), broadcast: false);

    Event::assertNotDispatched(InboxUpdated::class);
});

test('a message read again with its json in another key order is not saved or broadcast again', function () {
    $meta = ['zeta' => '1', 'alpha' => ['type' => 'image', 'url' => 'https://cdn.example/a.jpg']];
    $media = [['url' => 'https://cdn.example/a.jpg', 'type' => 'image']];
    $subjectMedia = [['url' => 'https://cdn.example/s.jpg', 'type' => 'image']];
    $message = UpsertInboxMessage::execute($this->account, inboxIncomingWith('at://fan/c1', ['meta' => $meta, 'media' => $media], ['media' => $subjectMedia]));
    DB::table('inbox_messages')->where('id', $message->id)->update([
        'meta' => '{"pending_media": {"media:0:path": "https://cdn.example/a.jpg"}, "alpha": {"url": "https://cdn.example/a.jpg", "type": "image"}, "zeta": "1"}',
        'media' => '[{"type": "image"}]',
        'updated_at' => '2026-10-01 00:00:00',
    ]);
    DB::table('inbox_conversations')->where('id', $message->inbox_conversation_id)->update([
        'subject_media' => '[{"type": "image"}]',
        'updated_at' => '2026-10-01 00:00:00',
    ]);
    Event::fake([InboxUpdated::class]);

    $touched = UpsertInboxMessages::execute($this->account, [inboxIncomingWith('at://fan/c1', ['meta' => $meta, 'media' => $media], ['media' => $subjectMedia])]);

    expect($touched)->toBeEmpty()
        ->and(InboxMessage::query()->sole()->updated_at->toDateString())->toBe('2026-10-01')
        ->and(InboxConversation::query()->sole()->updated_at->toDateString())->toBe('2026-10-01');
    Event::assertNotDispatched(InboxUpdated::class);
});

test('a first insert that loses the race to a concurrent one runs the upsert again', function () {
    $raced = false;
    InboxMessage::creating(function () use (&$raced): void {
        if (! $raced) {
            $raced = true;

            throw new UniqueConstraintViolationException('testing', 'insert into inbox_messages', [], new PDOException('duplicate key'));
        }
    });

    $message = UpsertInboxMessage::execute($this->account, inboxIncoming('at://fan/c1'));

    expect($raced)->toBeTrue()
        ->and($message->exists)->toBeTrue()
        ->and(InboxMessage::query()->count())->toBe(1)
        ->and(InboxConversation::query()->sole()->open_count)->toBe(1);
});
