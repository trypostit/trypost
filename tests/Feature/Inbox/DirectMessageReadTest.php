<?php

declare(strict_types=1);

use App\Actions\Inbox\CountOpenInboxMessages;
use App\Actions\Inbox\DeleteChannelInbox;
use App\Actions\Inbox\ResolveInboxMessages;
use App\Actions\Inbox\UnresolveInboxMessage;
use App\Actions\Inbox\UpsertInboxMessage;
use App\Dto\Inbox\IncomingMessage;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Status;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->travelTo('2026-10-16 12:00:00');
    fakeDirectMessageDriver();
    $this->account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create();
    $this->workspace = $this->account->workspace;
    $this->owner = $this->workspace->owner;
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    $this->dm = InboxConversation::query()->where('kind', 'direct_message')->sole();
});

test('the Messages tab lists DM conversations with their latest message and thread', function () {
    $this->actingAs($this->owner)
        ->get(route('app.inbox.index', ['tab' => 'messages']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'messages')
            ->where('filters.tab', 'messages')
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $this->dm->id)
            ->where('conversations.data.0.can_send_message', true)
            ->where('conversations.data.0.reply_window_ends_at', '2026-10-17T10:00:00+00:00')
            ->where('conversations.data.0.last_message.body', 'Hi there')
            ->where('conversation.id', $this->dm->id)
            ->has('directMessages.data', 1)
            ->where('directMessages.data.0.body', 'Hi there'));

    $this->actingAs($this->owner)
        ->get(route('app.inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'post')
            ->has('conversations.data', 1)
            ->whereNot('conversations.data.0.id', $this->dm->id));
});

test('a DM link opens the Messages tab', function () {
    $this->actingAs($this->owner)
        ->get(route('app.inbox.conversations.show', $this->dm))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('view', 'messages')
            ->where('filters.tab', 'messages')
            ->where('conversation.id', $this->dm->id));
});

test('open counts are split by tab and the badge sums them', function () {
    expect(CountOpenInboxMessages::execute($this->workspace, InboxTab::Messages)['total'])->toBe(1)
        ->and(CountOpenInboxMessages::execute($this->workspace, InboxTab::Comments)['total'])->toBe(1)
        ->and(CountOpenInboxMessages::execute($this->workspace)['total'])->toBe(2);
});

test('the Messages tab and DM channel fields appear while the inbox is on', function () {
    $this->actingAs($this->owner)
        ->get(route('app.inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tabCounts.messages', 1)
            ->where('inboxChannels.0.supports_direct_messages', true)
            ->where('inboxChannels.0.max_message_length', 1000)
            ->where('inboxChannels.0.message_length_unit', 'bytes'));
});

test('resolving all comments never resolves DMs', function () {
    ResolveInboxMessages::execute($this->workspace, $this->owner, ResolveScope::All, tab: InboxTab::Comments);

    expect($this->dm->fresh()->open_count)->toBe(1);

    ResolveInboxMessages::execute($this->workspace, $this->owner, ResolveScope::All, tab: InboxTab::Messages);

    expect($this->dm->fresh()->open_count)->toBe(0);
});

test('a member of another workspace cannot read a DM', function () {
    $outsider = User::factory()->create();
    $other = Workspace::factory()->create(['user_id' => $outsider->id]);
    $other->members()->attach($outsider->id, membershipPivot('admin'));
    $outsider->update(['current_workspace_id' => $other->id]);

    $this->actingAs($outsider)->get(route('app.inbox.conversations.show', $this->dm))->assertNotFound();
});

test('a member who needs approval reads DMs but cannot send', function () {
    $member = workspaceMember($this->workspace, 'approval');

    expect($member->can('view', $this->dm))->toBeTrue()
        ->and($member->can('message', $this->dm))->toBeFalse()
        ->and($this->owner->can('message', $this->dm))->toBeTrue()
        ->and($this->owner->can('message', InboxConversation::query()->where('kind', 'comment')->sole()))->toBeFalse();
});

test('disconnecting a channel deletes its DMs', function () {
    DeleteChannelInbox::forAccount($this->account);

    expect(InboxConversation::query()->exists())->toBeFalse();
});

test('the web list view sends the Messages tab to the conversation view', function () {
    $this->actingAs($this->owner)
        ->get(route('app.inbox.messages.index', ['tab' => 'messages']))
        ->assertRedirect(route('app.inbox.index', ['tab' => 'messages']));
});

test('undoing a resolve reopens the whole DM conversation', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', at: '2026-10-16 10:05:00'));
    ResolveInboxMessages::execute($this->workspace, $this->owner, ResolveScope::Conversation, conversation: $this->dm);

    expect($this->dm->fresh()->open_count)->toBe(0);

    UnresolveInboxMessage::execute(InboxMessage::query()->where('platform_message_id', 'm1')->sole());

    expect($this->dm->fresh()->open_count)->toBe(2);
});

test('a DM shows it cannot be sent to a member who needs approval', function () {
    $this->actingAs(workspaceMember($this->workspace, 'approval'))
        ->get(route('app.inbox.index', ['tab' => 'messages']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversations.data.0.can_send_message', false));
});

test('a DM of a channel that needs to be reconnected cannot be sent', function () {
    $this->account->update(['status' => Status::TokenExpired]);

    $this->actingAs($this->owner)
        ->get(route('app.inbox.index', ['tab' => 'messages']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('conversations.data.0.can_send_message', false));
});

test('every inbound message of a DM shows the participant picture, read once per request', function () {
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m2', at: '2026-10-16 10:05:00'));
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m3', at: '2026-10-16 10:10:00'));
    $this->dm->forceFill(['participant_avatar_path' => 'inbox/ana.jpg'])->save();
    InboxMessage::query()->where('platform_message_id', 'm2')->update(['author_avatar_path' => 'inbox/old-copy.jpg']);
    $conversationReads = 0;
    DB::listen(function ($query) use (&$conversationReads) {
        if (str_contains($query->sql, 'inbox_conversations') && str_contains($query->sql, 'limit 1') && in_array($this->dm->id, $query->bindings, true)) {
            $conversationReads++;
        }
    });

    $this->actingAs($this->owner)
        ->get(route('app.inbox.index', ['tab' => 'messages']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('directMessages.data', 3)
            ->where('directMessages.data.0.author.avatar_url', Storage::url('inbox/ana.jpg'))
            ->where('directMessages.data.1.author.avatar_url', Storage::url('inbox/ana.jpg'))
            ->where('directMessages.data.2.author.avatar_url', Storage::url('inbox/ana.jpg')));

    expect($conversationReads)->toBeLessThanOrEqual(2);
});

test('an inbound DM stores no picture of its own', function () {
    $incoming = inboxDirectIncoming('m9', at: '2026-10-16 11:00:00');
    UpsertInboxMessage::execute($this->account, new IncomingMessage(...[...get_object_vars($incoming), 'authorAvatarUrl' => 'https://cdn.example/ana.jpg']));

    $message = InboxMessage::query()->where('platform_message_id', 'm9')->sole();

    expect($message->author_avatar_path)->toBeNull()
        ->and(data_get($message->meta, 'pending_media'))->toBeEmpty();
});
