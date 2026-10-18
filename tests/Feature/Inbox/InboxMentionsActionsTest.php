<?php

declare(strict_types=1);

use App\Actions\Inbox\NotifyInboxActivity;
use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\ResolveInboxMessages;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ResolveScope;
use App\Events\InboxUpdated;
use App\Jobs\SendNotification;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->account = SocialAccount::factory()->bluesky()->create(['platform_user_id' => 'did:plc:me']);
});

test('a mention stores the mentioning author on the conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));

    $conversation = InboxConversation::query()->sole();

    expect($conversation->kind)->toBe(ConversationKind::Mention)
        ->and($conversation->participant_name)->toBe('Fan')
        ->and($conversation->participant_username)->toBe('fan.bsky.social')
        ->and($conversation->open_count)->toBe(1);
});

test('a reply joins its parent conversation whatever subject it carries', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:me/app.bsky.feed.post/r1', 'at://did:plc:fan/app.bsky.feed.post/m1', MessageDirection::Outbound, at: '2026-10-16 11:00:00'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', 'at://did:plc:me/app.bsky.feed.post/r1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 12:00:00'));

    $conversation = InboxConversation::query()->sole();
    $late = InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m2')->sole();

    expect($late->inbox_conversation_id)->toBe($conversation->id)
        ->and($late->root_id)->toBe(InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m1')->value('id'))
        ->and($conversation->fresh()->open_count)->toBe(1);
});

test('a mention whose parent is a stored comment stays in the comment conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('at://did:plc:fan/app.bsky.feed.post/c1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/c2', 'at://did:plc:fan/app.bsky.feed.post/c1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/c2'));

    expect(InboxConversation::query()->sole()->kind)->toBe(ConversationKind::Comment)
        ->and(InboxMessage::query()->count())->toBe(2);
});

test('resolve all only touches the tab kinds', function () {
    $user = User::factory()->create();
    $comment = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Mention]);
    InboxMessage::factory()->for($comment, 'conversation')->create();
    InboxMessage::factory()->for($mention, 'conversation')->create();
    RecountInboxConversation::execute($comment);
    RecountInboxConversation::execute($mention);

    $resolved = ResolveInboxMessages::execute($this->account->workspace, $user, ResolveScope::All, tab: InboxTab::Mentions);

    expect($resolved)->toBe(1)
        ->and($mention->fresh()->open_count)->toBe(0)
        ->and($comment->fresh()->open_count)->toBe(1);

    expect(ResolveInboxMessages::execute($this->account->workspace, $user, ResolveScope::All))->toBe(1)
        ->and($comment->fresh()->open_count)->toBe(0);
});

test('resolve older only touches the tab kinds', function () {
    $user = User::factory()->create();
    $comment = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Mention]);
    InboxMessage::factory()->for($comment, 'conversation')->create(['platform_created_at' => now()->subMonths(2)]);
    InboxMessage::factory()->for($mention, 'conversation')->create(['platform_created_at' => now()->subMonths(2)]);

    expect(ResolveInboxMessages::execute($this->account->workspace, $user, ResolveScope::Older))->toBe(1)
        ->and(InboxMessage::query()->whereNull('resolved_at')->sole()->inbox_conversation_id)->toBe($mention->id);
});

test('the web resolution endpoint resolves only the tab it is sent from', function () {
    $owner = $this->account->workspace->owner;
    $owner->update(['current_workspace_id' => $this->account->workspace_id]);
    $comment = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->account->id, 'kind' => ConversationKind::Mention]);
    InboxMessage::factory()->for($comment, 'conversation')->create();
    InboxMessage::factory()->for($mention, 'conversation')->create();

    $this->actingAs($owner)->post(route('app.inbox.resolutions.store'), ['scope' => 'all', 'tab' => 'mentions'])->assertRedirect();

    expect(InboxMessage::query()->whereNull('resolved_at')->sole()->inbox_conversation_id)->toBe($comment->id);

    $this->actingAs($owner)->post(route('app.inbox.resolutions.store'), ['scope' => 'all', 'tab' => 'dms'])->assertSessionHasErrors('tab');
});

test('mentions never trigger the activity email', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1', at: now()->subMinutes(5)->toDateTimeString()));
    InboxConversation::query()->update(['subject_published_at' => now()->subHour()]);

    expect(NotifyInboxActivity::execute())->toBe(0);
    Queue::assertNotPushed(SendNotification::class);
});

test('a message that moves to its parent conversation leaves the old one recounted', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 12:00:00'));
    $orphan = InboxConversation::query()->where('platform_conversation_id', 'at://did:plc:fan/app.bsky.feed.post/m2')->sole();

    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', 'at://did:plc:fan/app.bsky.feed.post/m1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 12:00:00'));

    expect($orphan->fresh()->open_count)->toBe(0)
        ->and($orphan->fresh()->message_count)->toBe(0)
        ->and(InboxConversation::query()->where('platform_conversation_id', 'at://did:plc:fan/app.bsky.feed.post/m1')->sole()->open_count)->toBe(2);
});

test('a later delivery without the parent keeps the known parent and conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', 'at://did:plc:fan/app.bsky.feed.post/m1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2'));

    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2'));

    $late = InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m2')->sole();
    $root = InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m1')->sole();

    expect($late->parent_id)->toBe($root->id)
        ->and($late->platform_parent_id)->toBe('at://did:plc:fan/app.bsky.feed.post/m1')
        ->and($late->inbox_conversation_id)->toBe($root->inbox_conversation_id)
        ->and(InboxConversation::query()->count())->toBe(1);
});

test('a message that moves to its parent conversation takes its replies with it', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 12:00:00'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m3', 'at://did:plc:fan/app.bsky.feed.post/m2', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 13:00:00'));
    $orphan = InboxConversation::query()->where('platform_conversation_id', 'at://did:plc:fan/app.bsky.feed.post/m2')->sole();

    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', 'at://did:plc:fan/app.bsky.feed.post/m1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2', at: '2026-10-16 12:00:00'));

    $target = InboxConversation::query()->where('platform_conversation_id', 'at://did:plc:fan/app.bsky.feed.post/m1')->sole();
    $reply = InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m3')->sole();

    expect($reply->inbox_conversation_id)->toBe($target->id)
        ->and($reply->root_id)->toBe(InboxMessage::query()->where('platform_message_id', 'at://did:plc:fan/app.bsky.feed.post/m1')->value('id'))
        ->and($target->open_count)->toBe(3)
        ->and($orphan->fresh()->message_count)->toBe(0);
});

test('the conversation a moved message left is refreshed live', function () {
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m1'));
    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2'));
    $orphan = InboxConversation::query()->where('platform_conversation_id', 'at://did:plc:fan/app.bsky.feed.post/m2')->sole();
    Event::fake([InboxUpdated::class]);

    UpsertInboxMessage::execute($this->account, inboxMention('at://did:plc:fan/app.bsky.feed.post/m2', 'at://did:plc:fan/app.bsky.feed.post/m1', subjectId: 'at://did:plc:fan/app.bsky.feed.post/m2'));

    Event::assertDispatched(InboxUpdated::class, fn (InboxUpdated $event): bool => $event->conversationId === $orphan->id);
});
