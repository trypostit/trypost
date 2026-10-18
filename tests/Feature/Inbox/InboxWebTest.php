<?php

declare(strict_types=1);

use App\Actions\Inbox\BuildInboxPageProps;
use App\Actions\Inbox\RecountInboxConversation;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Inertia\OnceProp;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create();
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    $this->user = $this->conversation->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->conversation->workspace_id]);
});

test('the inbox page lists conversations', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/Index', false)
            ->where('view', 'post')
            ->where('hasInboxChannels', true)
            ->where('hasConversations', true)
            ->where('isConversationOpened', false)
            ->has('conversations.data', 1));
});

test('the list view renders the messages', function () {
    $this->actingAs($this->user)->get(route('app.inbox.messages.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/Index', false)
            ->where('view', 'list')
            ->has('messages.data', 1));
});

test('every inbox route is a 404 when the module is off', function (string $method, string $name, string $binding, array $payload) {
    Queue::fake([SendInboxReply::class]);
    config()->set('trypost.inbox.enabled', false);
    $parameters = match ($binding) {
        'conversation' => [$this->conversation],
        'message' => [$this->comment],
        default => [],
    };

    $this->actingAs($this->user)->call($method, route($name, $parameters), $payload)->assertNotFound();
    Queue::assertNothingPushed();
})->with([
    'by post' => ['GET', 'app.inbox.index', '', []],
    'list' => ['GET', 'app.inbox.messages.index', '', []],
    'list with a bad filter' => ['GET', 'app.inbox.messages.index', '', ['status' => 'bogus']],
    'conversation' => ['GET', 'app.inbox.conversations.show', 'conversation', []],
    'direct message' => ['POST', 'app.inbox.conversations.messages.store', 'conversation', ['text' => 'hi']],
    'reply' => ['POST', 'app.inbox.replies.store', 'message', ['text' => 'hi']],
    'retry' => ['POST', 'app.inbox.replies.retry', 'message', []],
    'resolve' => ['POST', 'app.inbox.resolutions.store', '', ['scope' => 'all']],
    'unresolve' => ['DELETE', 'app.inbox.resolutions.destroy', 'message', []],
    'reorder hint' => ['POST', 'app.inbox.reorder-hint.store', '', []],
]);

test('the inbox badge is not shared when the module is off', function (bool $enabled) {
    config()->set('trypost.inbox.enabled', $enabled);

    $this->actingAs($this->user)->get(route('app.settings.preferences'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $enabled ? $page->has('inbox') : $page->missing('inbox'));
})->with(['on' => [true], 'off' => [false]]);

test('replying queues the reply and goes back', function () {
    Queue::fake([SendInboxReply::class]);

    $this->actingAs($this->user)
        ->from(route('app.inbox.conversations.show', $this->conversation))
        ->post(route('app.inbox.replies.store', $this->comment), ['text' => 'thanks!'])
        ->assertRedirect(route('app.inbox.conversations.show', $this->conversation));

    Queue::assertPushed(SendInboxReply::class);
});

test('a reply over the channel limit is refused', function () {
    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $this->comment), ['text' => str_repeat('a', 301)])
        ->assertSessionHasErrors('text');
});

test('a member who needs approval gets a 403 on reply', function () {
    $member = workspaceMember($this->conversation->workspace, 'approval');

    $this->actingAs($member)->post(route('app.inbox.replies.store', $this->comment), ['text' => 'hi'])->assertForbidden();
});

test('a conversation of another workspace is a 404', function () {
    $foreign = InboxConversation::factory()->create();

    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $foreign))->assertNotFound();
});

test('resolving the conversation zeroes its open count', function () {
    $this->actingAs($this->user)
        ->post(route('app.inbox.resolutions.store'), ['scope' => 'conversation', 'conversation_id' => $this->conversation->id])
        ->assertRedirect();

    expect($this->conversation->fresh()->open_count)->toBe(0);
});

test('a resolved message can be reopened', function () {
    $this->comment->update(['resolved_at' => now()]);

    $this->actingAs($this->user)
        ->delete(route('app.inbox.resolutions.destroy', $this->comment))
        ->assertRedirect();

    expect($this->comment->fresh()->resolved_at)->toBeNull();
});

test('the badge count is shared on every page', function () {
    RecountInboxConversation::execute($this->conversation);

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('inbox.total', 1));
});

test('the shared channels keep non-inbox channels while inboxChannels lists only inbox ones', function () {
    $workspace = $this->conversation->workspace;
    $x = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('channels', fn ($channels) => collect($channels)->contains('id', $x->id))
            ->has('inboxChannels', 1)
            ->where('inboxChannels.0.platform', 'bluesky'));
});

test('a member who needs approval cannot resolve by conversation, all or older', function (array $payload) {
    $member = workspaceMember($this->conversation->workspace, 'approval');
    $payload = array_map(fn ($value) => $value === ':conversation' ? $this->conversation->id : $value, $payload);

    $this->actingAs($member)->post(route('app.inbox.resolutions.store'), $payload)->assertForbidden();
})->with([
    'conversation' => [['scope' => 'conversation', 'conversation_id' => ':conversation']],
    'all' => [['scope' => 'all']],
    'older' => [['scope' => 'older']],
]);

test('a member who needs approval gets 403 before validation on an over-length reply', function () {
    $member = workspaceMember($this->conversation->workspace, 'approval');

    $this->actingAs($member)->post(route('app.inbox.replies.store', $this->comment), ['text' => str_repeat('a', 301)])->assertForbidden();
});

test('a whitespace-only reply is a validation error', function () {
    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $this->comment), ['text' => '   '])
        ->assertSessionHasErrors('text');
});

test('the list view carries each comment post and the comments replied in the last 7 days', function () {
    $this->comment->forceFill(['answered_at' => now()->subDay()])->save();
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['answered_at' => now()->subDays(8)]);

    $this->actingAs($this->user)->get(route('app.inbox.messages.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/Index', false)
            ->where('repliedRecentlyCount', 1)
            ->where('messages.data.0.conversation.id', $this->conversation->id)
            ->where('messages.data.0.conversation.subject.text', $this->conversation->subject_text));
});

test('the post view leaves out the replied count', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('inbox/Index', false)
            ->where('repliedRecentlyCount', null));
});

test('retrying a failed reply on the web sends the same row and goes back', function () {
    Queue::fake([SendInboxReply::class]);
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'delivery_status' => DeliveryStatus::Failed]);

    $this->actingAs($this->user)
        ->from(route('app.inbox.index'))
        ->post(route('app.inbox.replies.retry', $failed))
        ->assertRedirect(route('app.inbox.index'));

    expect($failed->fresh()->delivery_status)->toBe(DeliveryStatus::Sending);
    Queue::assertPushed(SendInboxReply::class);
});

test('retrying a reply of another workspace is a 404 on the web', function () {
    $foreign = InboxMessage::factory()->outbound()->create(['delivery_status' => DeliveryStatus::Failed]);

    $this->actingAs($this->user)->post(route('app.inbox.replies.retry', $foreign))->assertNotFound();
});

test('a reply that did not fail is refused on the web', function () {
    $this->actingAs($this->user)->post(route('app.inbox.replies.retry', $this->comment))->assertSessionHasErrors('message');
});

test('a workspace without an inbox channel gets the first-use state', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->update(['current_workspace_id' => $workspace->id]);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);

    $this->actingAs($owner)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('hasInboxChannels', false)
            ->where('hasConversations', false)
            ->where('inboxChannels', []));
});

test('a connected channel without comments yet keeps the inbox layout', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->update(['current_workspace_id' => $workspace->id]);
    Queue::fake();
    SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('hasInboxChannels', true)
            ->where('hasConversations', false)
            ->has('inboxChannels', 1)
            ->where('conversation', null));
});

test('a network without an inbox driver is not offered', function () {
    app()->instance(InboxDrivers::class, new class extends InboxDrivers
    {
        public function for(Platform $platform): ?InboxDriver
        {
            return $platform === Platform::Bluesky ? parent::for($platform) : null;
        }
    });
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->update(['current_workspace_id' => $workspace->id]);
    Queue::fake();
    SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($owner)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('hasInboxChannels', false)
            ->where('inboxChannels', [])
            ->where('inbox.total', 0));
});

test('opening a conversation by its link tells the page it is open', function () {
    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $this->conversation))
        ->assertInertia(fn ($page) => $page->where('isConversationOpened', true)->where('conversation.id', $this->conversation->id));
});

test('the reorder hint shows until the user dismisses it', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('showsReorderHint', true));

    $this->actingAs($this->user)->from(route('app.inbox.index'))
        ->post(route('app.inbox.reorder-hint.store'))
        ->assertRedirect(route('app.inbox.index'));

    expect($this->user->fresh()->inbox_reorder_hint_seen_at)->not->toBeNull();

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('showsReorderHint', false));
});

test('the reply limit comes from the inbox driver', function () {
    $this->mock(BlueskyInboxDriver::class)
        ->shouldReceive('replyLength')->andReturn(10)
        ->shouldReceive('replyLengthUnit')->andReturn(ReplyLengthUnit::Characters)
        ->shouldReceive('prefillsMention')->andReturn(true)
        ->shouldReceive('kinds')->andReturn([ConversationKind::Comment]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $this->comment), ['text' => str_repeat('a', 11)])
        ->assertSessionHasErrors('text');

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page->where('inboxChannels.0.max_reply_length', 10));
});

test('resolving a thread on the web resolves its open replies', function () {
    $nested = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'root_id' => $this->comment->id]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.resolutions.store'), ['scope' => 'thread', 'message_id' => $this->comment->id])
        ->assertRedirect();

    expect($nested->fresh()->resolved_at)->not->toBeNull();
});

test('a thread resolve without a message is a validation error', function () {
    $this->actingAs($this->user)
        ->post(route('app.inbox.resolutions.store'), ['scope' => 'thread'])
        ->assertSessionHasErrors('message_id');
});

test('replying to our own reply is refused on the web', function () {
    Queue::fake([SendInboxReply::class]);
    $ours = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id]);

    $this->actingAs($this->user)
        ->post(route('app.inbox.replies.store', $ours), ['text' => 'hi'])
        ->assertSessionHasErrors('message');

    Queue::assertNothingPushed();
});

test('an expired inbox channel keeps the inbox layout with its conversations and a reconnect banner', function () {
    $this->conversation->socialAccount->forceFill(['status' => Status::TokenExpired])->saveQuietly();

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('hasInboxChannels', true)
            ->where('hasConversations', true)
            ->has('conversations.data', 1)
            ->where('conversations.data.0.id', $this->conversation->id)
            ->has('inboxChannels', 1)
            ->where('inboxChannels.0.id', $this->conversation->social_account_id)
            ->where('inboxChannels.0.is_connected', false));
});

test('the conversation link switches to its workspace', function () {
    $other = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $other->members()->attach($this->user->id, membershipPivot('admin'));
    $this->conversation->workspace->members()->syncWithoutDetaching([$this->user->id => membershipPivot('admin')]);
    $this->user->update(['current_workspace_id' => $other->id]);

    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $this->conversation))
        ->assertRedirect(route('app.inbox.conversations.show', $this->conversation));

    expect($this->user->fresh()->current_workspace_id)->toBe($this->conversation->workspace_id);

    $this->actingAs($this->user->fresh())->get(route('app.inbox.conversations.show', $this->conversation))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('conversation.id', $this->conversation->id));
});

test('the conversation link of a workspace on another account is a 404 and keeps the current workspace', function () {
    $foreign = InboxConversation::factory()->create();
    $foreign->workspace->members()->attach($this->user->id, membershipPivot('admin'));

    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $foreign))->assertNotFound();

    expect($this->user->fresh()->current_workspace_id)->toBe($this->conversation->workspace_id);
});

test('inbox channels carry their kinds and reply unit', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn ($page) => $page
            ->where('inboxChannels.0.kinds', ['comment', 'mention'])
            ->where('inboxChannels.0.reply_length_unit', 'characters'));
});

test('by post with a review-only channel opens the list view with the same filters', function () {
    $reviews = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->conversation->workspace_id]);

    $this->actingAs($this->user)->get(route('app.inbox.index', ['channels' => [$reviews->id], 'status' => 'all']))
        ->assertRedirect(route('app.inbox.messages.index', ['channels' => [$reviews->id], 'status' => 'all']));
});

test('by post with a comment channel stays in by post', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index', ['channels' => [$this->conversation->social_account_id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('view', 'post'));
});

test('a review link opens the list view on its channel instead of by post', function () {
    $review = InboxConversation::factory()->create([
        'workspace_id' => $this->conversation->workspace_id,
        'kind' => ConversationKind::Review,
    ]);

    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $review))
        ->assertRedirect(route('app.inbox.messages.index', ['channels' => [$review->social_account_id], 'status' => 'all']));
});

test('the mentions tab renders the feed with its own count', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'kind' => ConversationKind::Mention]);
    InboxMessage::factory()->for($conversation, 'conversation')->create([
        'platform_message_id' => $conversation->platform_conversation_id,
        'platform_message_hash' => $conversation->platform_conversation_hash,
    ]);
    RecountInboxConversation::execute($conversation);
    RecountInboxConversation::execute($this->conversation);

    $this->actingAs($this->user)
        ->get(route('app.inbox.messages.index', ['tab' => 'mentions']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('inbox/Index', false)
            ->where('view', 'list')
            ->where('filters.tab', 'mentions')
            ->where('tabCounts.comments', 1)
            ->where('tabCounts.mentions', 1)
            ->where('repliedRecentlyCount', null)
            ->where('hasConversations', true)
            ->where('inboxChannels.0.open_count', 1)
            ->where('inboxChannels.0.supports_mentions', true)
            ->where('inboxChannels.0.has_mention_access', true)
            ->where('mentionsAvailability', [
                ['platform' => 'facebook', 'state' => 'available'],
                ['platform' => 'instagram', 'state' => 'available'],
                ['platform' => 'threads', 'state' => 'available'],
                ['platform' => 'bluesky', 'state' => 'available'],
                ['platform' => 'mastodon', 'state' => 'available'],
                ['platform' => 'x', 'state' => 'coming_soon'],
            ])
            ->has('messages.data', 1)
            ->where('messages.data.0.conversation_id', $conversation->id)
            ->where('messages.data.0.is_subject', true));
});

test('the mentions tab has no by post view', function () {
    $this->actingAs($this->user)
        ->get(route('app.inbox.index', ['tab' => 'mentions', 'status' => 'all']))
        ->assertRedirect(route('app.inbox.messages.index', ['tab' => 'mentions', 'status' => 'all']));
});

test('a mention deep link opens the mentions feed of its channel', function () {
    $conversation = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'kind' => ConversationKind::Mention]);

    $this->actingAs($this->user)
        ->get(route('app.inbox.conversations.show', $conversation))
        ->assertRedirect(route('app.inbox.messages.index', ['tab' => 'mentions', 'channels' => [$conversation->social_account_id], 'status' => 'all']));
});

test('an unknown tab is refused', function () {
    $this->actingAs($this->user)->getJson(route('app.inbox.index', ['tab' => 'dms']))->assertUnprocessable()->assertJsonValidationErrors('tab');
});

test('the inbox filter visit asks for every prop of the page except the once props it keeps', function () {
    $source = (string) file_get_contents(resource_path('js/lib/inbox.ts'));
    $listed = fn (string $name): array => preg_match("/const {$name} = \\[(.*?)\\];/s", $source, $match) === 1
        ? (preg_match_all("/'([A-Za-z]+)'/", $match[1], $keys) ? $keys[1] : [])
        : [];
    $pageKeys = [...$listed('INBOX_RELOAD_KEYS'), ...$listed('INBOX_PAGE_KEYS')];
    $props = array_keys(array_filter(
        BuildInboxPageProps::execute($this->user, $this->conversation->workspace, InboxFilters::fromArray([]), 'list'),
        fn (mixed $prop): bool => ! $prop instanceof OnceProp,
    ));

    expect($listed('INBOX_PAGE_KEYS'))->not->toBe([])
        ->and(array_diff($props, $pageKeys))->toBe([]);
});

test('the mentions availability lists each network once', function () {
    $this->actingAs($this->user)->get(route('app.inbox.messages.index', ['tab' => 'mentions']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('mentionsAvailability', [
            ['platform' => 'facebook', 'state' => 'available'],
            ['platform' => 'instagram', 'state' => 'available'],
            ['platform' => 'threads', 'state' => 'available'],
            ['platform' => 'bluesky', 'state' => 'available'],
            ['platform' => 'mastodon', 'state' => 'available'],
            ['platform' => 'x', 'state' => 'coming_soon'],
        ]));
});

test('by post sends the open conversation threads as a scroll prop', function () {
    config()->set('app.pagination.default', 1);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()]);

    $this->actingAs($this->user)->get(route('app.inbox.conversations.show', $this->conversation))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversationThreads.data', 1)
            ->missing('conversation.messages')
            ->where('conversation.replied_count', 0));
});

test('the next page of a post comments keeps to the post it was opened on', function () {
    config()->set('app.pagination.default', 1);
    $opened = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'last_message_at' => now()->subDay()]);
    $first = InboxMessage::factory()->for($opened, 'conversation')->create(['platform_created_at' => now()]);
    $second = InboxMessage::factory()->for($opened, 'conversation')->create(['platform_created_at' => now()->subHour()]);

    $this->actingAs($this->user)
        ->get(route('app.inbox.index', ['thread_conversation' => $opened->id, 'thread_page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversationThreads.data', 1)
            ->where('conversationThreads.data.0.id', $second->id));
});

test('reading the post comments again around a comment returns the page that holds it', function () {
    config()->set('app.pagination.default', 1);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()]);
    $reply = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'root_id' => $this->comment->id, 'platform_created_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('app.inbox.conversations.show', [$this->conversation, 'thread_around' => $reply->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('conversationThreads.data', 1)
            ->where('conversationThreads.data.0.id', $this->comment->id));
});

test('a workspace whose inbox channels all have reviews only opens the list view', function () {
    Queue::fake();
    $account = SocialAccount::factory()->googleBusiness()->withInboxScopes()->create();
    $user = $account->workspace->owner;
    $user->update(['current_workspace_id' => $account->workspace_id]);

    $this->actingAs($user)
        ->get(route('app.inbox.index', ['status' => 'all', 'thread_around' => (string) str()->uuid()]))
        ->assertRedirect(route('app.inbox.messages.index', ['status' => 'all']));
});

test('a member who needs approval gets 403 before validation on a bulk resolve', function () {
    $member = workspaceMember($this->conversation->workspace, 'approval');

    $this->actingAs($member)->post(route('app.inbox.resolutions.store'), ['scope' => 'all', 'tab' => 'nope'])->assertForbidden();
});

test('replying, resolving or reopening a message of another of the owner workspaces is a 404 on the web', function () {
    $other = InboxConversation::factory()->create(['workspace_id' => Workspace::factory()->create(['user_id' => $this->user->id])->id]);
    $other->socialAccount->update(['workspace_id' => $other->workspace_id]);
    $foreign = InboxMessage::factory()->for($other, 'conversation')->create(['social_account_id' => $other->social_account_id, 'resolved_at' => now()]);

    $this->actingAs($this->user)->post(route('app.inbox.replies.store', $foreign), ['text' => 'hi'])->assertNotFound();
    $this->actingAs($this->user)->delete(route('app.inbox.resolutions.destroy', $foreign))->assertNotFound();
    $this->actingAs($this->user)->post(route('app.inbox.resolutions.store'), ['scope' => 'message', 'message_id' => $foreign->id])->assertNotFound();

    expect($foreign->fresh()->resolved_at)->not->toBeNull();
});

test('sending from the inbox shares one named limit on the web and the api', function (string $route) {
    expect(Route::getRoutes()->getByName($route)->gatherMiddleware())->toContain('throttle:inbox-send')
        ->and(RateLimiter::limiter('inbox-send'))->not->toBeNull();
})->with([
    'app.inbox.replies.store', 'app.inbox.replies.retry', 'app.inbox.conversations.messages.store',
    'api.inbox.replies.store', 'api.inbox.replies.retry', 'api.inbox.conversations.messages.store',
]);

test('filtering by two channels keeps only their conversations, messages and counts', function () {
    RecountInboxConversation::execute($this->conversation);
    $second = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->conversation->workspace_id])->id]);
    InboxMessage::factory()->for($second, 'conversation')->create();
    RecountInboxConversation::execute($second);
    $third = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->conversation->workspace_id])->id]);
    InboxMessage::factory()->for($third, 'conversation')->create();
    RecountInboxConversation::execute($third);
    $channels = [$this->conversation->social_account_id, $second->social_account_id];

    $this->actingAs($this->user)->get(route('app.inbox.index', ['channels' => $channels]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.channels', $channels)
            ->has('conversations.data', 2)
            ->where('tabCounts.comments', 2));

    $this->actingAs($this->user)->get(route('app.inbox.messages.index', ['channels' => $channels]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('messages.data', 2));
});

test('no channel filter keeps every channel', function () {
    $second = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->conversation->workspace_id])->id]);
    InboxMessage::factory()->for($second, 'conversation')->create();

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.channels', [])->has('conversations.data', 2));
});

test('a channel of another workspace is refused', function () {
    $foreign = SocialAccount::factory()->bluesky()->create();

    $this->actingAs($this->user)->getJson(route('app.inbox.index', ['channels' => [$this->conversation->social_account_id, $foreign->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.1');

    $this->actingAs($this->user)->get(route('app.inbox.messages.index', ['channels' => [$foreign->id]]))
        ->assertRedirect(route('app.inbox.index'))
        ->assertSessionHasErrors('channels.0');
});

test('the channel filter lists every channel and flags the ones without an inbox', function () {
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->conversation->workspace_id]);
    $linkedInPage = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->conversation->workspace_id]);

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('filterChannels', 3)
            ->where('filterChannels', fn ($channels) => collect($channels)->firstWhere('id', $pinterest->id)['has_inbox'] === false
                && collect($channels)->firstWhere('id', $linkedInPage->id)['has_inbox'] === false
                && collect($channels)->firstWhere('id', $this->conversation->social_account_id)['has_inbox'] === true)
            ->has('inboxChannels', 1));
});

test('a channel without an inbox cannot be picked', function () {
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->conversation->workspace_id]);

    $this->actingAs($this->user)->getJson(route('app.inbox.index', ['channels' => [$pinterest->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.0');
});

test('dismissing the reorder hint again keeps the first time it was seen', function () {
    $this->user->forceFill(['inbox_reorder_hint_seen_at' => '2026-10-01 09:00:00'])->save();

    $this->actingAs($this->user)->post(route('app.inbox.reorder-hint.store'))->assertRedirect();

    expect($this->user->fresh()->inbox_reorder_hint_seen_at->toDateTimeString())->toBe('2026-10-01 09:00:00');
});
