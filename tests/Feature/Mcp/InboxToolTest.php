<?php

declare(strict_types=1);

use App\Enums\Inbox\DeliveryStatus;
use App\Jobs\Inbox\SendInboxReply;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\GetInboxConversationTool;
use App\Mcp\Tools\Inbox\GetInboxScoreTool;
use App\Mcp\Tools\Inbox\ListInboxConversationsTool;
use App\Mcp\Tools\Inbox\ListInboxMessagesTool;
use App\Mcp\Tools\Inbox\ReplyToInboxMessageTool;
use App\Mcp\Tools\Inbox\ResolveInboxMessagesTool;
use App\Mcp\Tools\Inbox\RetryInboxReplyTool;
use App\Mcp\Tools\Inbox\SendInboxDirectMessageTool;
use App\Mcp\Tools\Inbox\UnresolveInboxMessageTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $this->conversation = InboxConversation::factory()->create(['social_account_id' => $this->account->id]);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    Queue::fake([SendInboxReply::class]);
});

test('list conversations returns the page with the resource shape', function () {
    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('total', 1)->etc()->has('conversations', 1, fn (AssertableJson $c) => $c->where('id', $this->conversation->id)->etc());
        });
});

test('list conversations rejects a malformed channel id', function () {
    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, ['channel_ids' => ['nope']])->assertHasErrors();
});

test('the list tools filter by several channels', function (string $tool) {
    $second = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id])->id]);
    InboxMessage::factory()->for($second, 'conversation')->create();
    $third = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id])->id]);
    InboxMessage::factory()->for($third, 'conversation')->create();

    TryPostServer::actingAs($this->user)->tool($tool, ['channel_ids' => [$this->account->id, $second->social_account_id]])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 2)->etc());

    TryPostServer::actingAs($this->user)->tool($tool, [])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->where('total', 3)->etc());
})->with([ListInboxConversationsTool::class, ListInboxMessagesTool::class]);

test('the list tools refuse a channel of another workspace', function (string $tool) {
    TryPostServer::actingAs($this->user)->tool($tool, ['channel_ids' => [$this->account->id, SocialAccount::factory()->bluesky()->create()->id]])
        ->assertHasErrors();
})->with([ListInboxConversationsTool::class, ListInboxMessagesTool::class]);

test('the list tools refuse a channel without an inbox', function (string $tool) {
    TryPostServer::actingAs($this->user)->tool($tool, ['channel_ids' => [SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->workspace->id])->id]])
        ->assertHasErrors();
})->with([ListInboxConversationsTool::class, ListInboxMessagesTool::class]);

test('list messages returns the top level comments', function () {
    TryPostServer::actingAs($this->user)->tool(ListInboxMessagesTool::class, [])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->etc()->has('messages', 1, fn (AssertableJson $m) => $m->where('id', $this->comment->id)->etc());
        });
});

test('get conversation returns it with its messages', function () {
    TryPostServer::actingAs($this->user)->tool(GetInboxConversationTool::class, ['conversation_id' => $this->conversation->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('id', $this->conversation->id)->has('messages', 1)->etc();
        });
});

test('get conversation refuses a malformed id and another workspace', function () {
    TryPostServer::actingAs($this->user)->tool(GetInboxConversationTool::class, ['conversation_id' => 'nope'])->assertHasErrors();
    $foreign = InboxConversation::factory()->create();
    TryPostServer::actingAs($this->user)->tool(GetInboxConversationTool::class, ['conversation_id' => $foreign->id])->assertHasErrors(['Inbox conversation not found.']);
});

test('reply queues the job and returns the outbound message', function () {
    TryPostServer::actingAs($this->user)->tool(ReplyToInboxMessageTool::class, ['message_id' => $this->comment->id, 'text' => 'Thanks!'])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('delivery_status', 'sending')->where('direction', 'outbound')->where('body', 'Thanks!')->etc();
        });

    Queue::assertPushed(SendInboxReply::class);
});

test('a member who needs approval cannot reply', function () {
    $member = User::factory()->create();
    $this->workspace->members()->attach($member->id, membershipPivot('approval'));
    $member->update(['current_workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($member)->tool(ReplyToInboxMessageTool::class, ['message_id' => $this->comment->id, 'text' => 'Thanks!'])->assertHasErrors();

    Queue::assertNothingPushed();
});

test('resolve and unresolve a message', function () {
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'message', 'message_id' => $this->comment->id])
        ->assertOk()
        ->assertStructuredContent(['resolved' => 1]);

    expect($this->comment->fresh()->resolved_at)->not->toBeNull();

    TryPostServer::actingAs($this->user)->tool(UnresolveInboxMessageTool::class, ['message_id' => $this->comment->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('id', $this->comment->id)->where('resolved_at', null)->etc();
        });
});

test('resolve by conversation and by channel', function () {
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'conversation', 'conversation_id' => $this->conversation->id])
        ->assertStructuredContent(['resolved' => 1]);

    $other = InboxMessage::factory()->for($this->conversation, 'conversation')->create();

    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'all', 'channel_ids' => [$this->account->id]])
        ->assertStructuredContent(['resolved' => 1]);

    expect($other->fresh()->resolved_at)->not->toBeNull();
});

test('resolve validates its input before looking anything up', function () {
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'message', 'message_id' => 'nope'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'nonsense'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'all', 'channel_ids' => [SocialAccount::factory()->bluesky()->create()->id]])->assertHasErrors(['Social account not found.']);
});

test('every tool says the inbox is not enabled when the module is off', function (string $tool, array $arguments) {
    config()->set('trypost.inbox.enabled', false);

    TryPostServer::actingAs($this->user)->tool($tool, $arguments)->assertHasErrors(['The inbox is not enabled.']);
})->with([
    'list conversations' => [ListInboxConversationsTool::class, []],
    'get conversation' => [GetInboxConversationTool::class, ['conversation_id' => '00000000-0000-4000-8000-000000000000']],
    'list messages' => [ListInboxMessagesTool::class, []],
    'score' => [GetInboxScoreTool::class, []],
    'reply' => [ReplyToInboxMessageTool::class, ['message_id' => '00000000-0000-4000-8000-000000000000', 'text' => 'hi']],
    'resolve' => [ResolveInboxMessagesTool::class, ['scope' => 'all']],
    'unresolve' => [UnresolveInboxMessageTool::class, ['message_id' => '00000000-0000-4000-8000-000000000000']],
    'retry' => [RetryInboxReplyTool::class, ['message_id' => '00000000-0000-4000-8000-000000000000']],
    'direct message' => [SendInboxDirectMessageTool::class, ['conversation_id' => '00000000-0000-4000-8000-000000000000', 'text' => 'hi']],
]);

test('retry sends a failed reply again', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'delivery_status' => DeliveryStatus::Failed]);

    TryPostServer::actingAs($this->user)->tool(RetryInboxReplyTool::class, ['message_id' => $failed->id])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($failed) {
            $json->where('id', $failed->id)->where('delivery_status', 'sending')->etc();
        });

    Queue::assertPushed(SendInboxReply::class);
});

test('retry refuses a reply that did not fail and another workspace', function () {
    $foreign = InboxMessage::factory()->outbound()->create(['delivery_status' => DeliveryStatus::Failed]);

    TryPostServer::actingAs($this->user)->tool(RetryInboxReplyTool::class, ['message_id' => $this->comment->id])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(RetryInboxReplyTool::class, ['message_id' => $foreign->id])->assertHasErrors(['Inbox message not found.']);
    TryPostServer::actingAs($this->user)->tool(RetryInboxReplyTool::class, ['message_id' => 'nope'])->assertHasErrors();
    Queue::assertNothingPushed();
});
