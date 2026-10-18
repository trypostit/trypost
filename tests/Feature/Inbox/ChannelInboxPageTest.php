<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->conversation = InboxConversation::factory()->create();
    InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    $this->channel = $this->conversation->socialAccount;
    $this->workspace = $this->conversation->workspace;
    $this->user = $this->workspace->owner;
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->other = InboxConversation::factory()->create(['workspace_id' => $this->workspace->id, 'social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id])->id]);
    InboxMessage::factory()->for($this->other, 'conversation')->create();
});

test('a channel inbox lists only that channel, holds it in the URL and offers no channel filter', function (string $route, string $list, string $channelKey) {
    $this->actingAs($this->user)->get(route($route, $this->channel))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('inbox/Index', false)
            ->where('filters.channel', $this->channel->id)
            ->where('filters.channels', [])
            ->has('inboxChannels', 1)
            ->where('inboxChannels.0.id', $this->channel->id)
            ->has("{$list}.data", 1)
            ->where("{$list}.data.0.{$channelKey}", $this->channel->id));
})->with([
    'by post' => ['app.channels.inbox', 'conversations', 'channel.id'],
    'list' => ['app.channels.inbox.messages', 'messages', 'conversation.channel.id'],
]);

test('the workspace inbox keeps every channel and no channel scope', function () {
    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.channel', null)->has('inboxChannels', 2)->has('conversations.data', 2));
});

test('a channel inbox redirects within the channel', function () {
    $this->actingAs($this->user)->get(route('app.channels.inbox', [$this->channel, 'tab' => 'mentions']))
        ->assertRedirect(route('app.channels.inbox.messages', [$this->channel, 'tab' => 'mentions']));
});

test('a conversation opens under its own channel only', function () {
    $this->actingAs($this->user)->get(route('app.channels.inbox.conversations', [$this->channel, $this->conversation]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.channel', $this->channel->id)->where('conversation.id', $this->conversation->id));

    $this->actingAs($this->user)->get(route('app.channels.inbox.conversations', [$this->channel, $this->other]))->assertNotFound();
});

test('a channel without an inbox or of another workspace has no inbox page', function (Closure $channel) {
    $this->actingAs($this->user)->get(route('app.channels.inbox', $channel($this->workspace)))->assertNotFound();
})->with([
    'no inbox on its network' => [fn (Workspace $workspace) => SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id])],
    'another workspace' => [fn () => SocialAccount::factory()->bluesky()->create()],
]);

test('a channel inbox shows only the tabs its channel has', function () {
    $youtube = SocialAccount::factory()->youtube()->withInboxScopes()->create(['workspace_id' => $this->workspace->id]);
    InboxConversation::factory()->create(['workspace_id' => $this->workspace->id, 'social_account_id' => $youtube->id, 'kind' => ConversationKind::Comment]);

    $this->actingAs($this->user)->get(route('app.channels.inbox', $youtube))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tabCounts.mentions', null)->where('tabCounts.messages', null));
});

test('the sidebar channels say which ones have an inbox', function () {
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get(route('app.inbox.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('channels', fn ($channels) => collect($channels)->firstWhere('id', $this->channel->id)['has_inbox'] === true
                && collect($channels)->firstWhere('id', $x->id)['has_inbox'] === false));
});
