<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Jobs\Inbox\SendInboxReply;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\GetInboxConversationTool;
use App\Mcp\Tools\Inbox\ListInboxConversationsTool;
use App\Mcp\Tools\Inbox\ListInboxMessagesTool;
use App\Mcp\Tools\Inbox\ReplyToInboxMessageTool;
use App\Mcp\Tools\Inbox\ResolveInboxMessagesTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $this->conversation = InboxConversation::factory()->create(['social_account_id' => $account->id]);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create();
    Queue::fake([SendInboxReply::class]);
});

test('the reply rules refuse the same text on web, api and mcp', function (string $text) {
    $this->actingAs($this->user)->postJson(route('app.inbox.replies.store', $this->comment), ['text' => $text])->assertUnprocessable();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.replies.store', $this->comment), ['text' => $text])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(ReplyToInboxMessageTool::class, ['message_id' => $this->comment->id, 'text' => $text])->assertHasErrors();

    Queue::assertNothingPushed();
})->with(['', '   ', str_repeat('a', 301)]);

test('listing conversations returns the same fields on the api and mcp', function () {
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.index'))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class)
        ->assertOk()
        ->assertStructuredContent(parityMcpPage('conversations', $api));
});

test('a conversation of another workspace is refused everywhere', function () {
    $foreign = InboxMessage::factory()->create();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.replies.store', $foreign), ['text' => 'hi'])->assertNotFound();
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(ReplyToInboxMessageTool::class, ['message_id' => $foreign->id, 'text' => 'hi'])->assertHasErrors();
});

test('resolving a thread resolves its open replies on web, api and mcp', function (string $surface) {
    $nested = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'root_id' => $this->comment->id]);
    $payload = ['scope' => 'thread', 'message_id' => $this->comment->id];

    match ($surface) {
        'web' => $this->actingAs($this->user)->post(route('app.inbox.resolutions.store'), $payload)->assertRedirect(),
        'api' => $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.resolutions.store'), $payload)->assertOk()->assertJsonPath('resolved', 2),
        'mcp' => TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, $payload)->assertOk()->assertSee('"resolved":2'),
    };

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($nested->fresh()->resolved_at)->not->toBeNull();
})->with(['web', 'api', 'mcp']);

test('resolving older comments keeps to the chosen channel on web, api and mcp', function (string $surface) {
    $this->comment->update(['platform_created_at' => now()->subMonths(2)]);
    $other = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->workspace->id]);
    $elsewhere = InboxMessage::factory()->for(InboxConversation::factory()->create(['social_account_id' => $other->id]), 'conversation')
        ->create(['platform_created_at' => now()->subMonths(2)]);
    $channelKey = $surface === 'mcp' ? 'channel_ids' : 'channels';
    $payload = ['scope' => 'older', $channelKey => [$this->conversation->social_account_id]];

    match ($surface) {
        'web' => $this->actingAs($this->user)->post(route('app.inbox.resolutions.store'), $payload)->assertRedirect(),
        'api' => $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.resolutions.store'), $payload)->assertOk(),
        'mcp' => TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, $payload)->assertOk(),
    };

    expect($this->comment->fresh()->resolved_at)->not->toBeNull()
        ->and($elsewhere->fresh()->resolved_at)->toBeNull();
})->with(['web', 'api', 'mcp']);

test('a reply to our own reply is refused on web, api and mcp', function () {
    $ours = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id]);

    $this->actingAs($this->user)->postJson(route('app.inbox.replies.store', $ours), ['text' => 'hi'])->assertUnprocessable()->assertJsonValidationErrors('message');
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.replies.store', $ours), ['text' => 'hi'])->assertUnprocessable()->assertJsonValidationErrors('message');
    TryPostServer::actingAs($this->user)->tool(ReplyToInboxMessageTool::class, ['message_id' => $ours->id, 'text' => 'hi'])->assertHasErrors([__('inbox.errors.not_replyable')]);

    Queue::assertNothingPushed();
});

test('listing mentions returns the same rows on the api and mcp', function (string $route, string $tool, string $key) {
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'kind' => ConversationKind::Mention]);
    InboxMessage::factory()->for($mention, 'conversation')->create();

    $api = $this->withHeaders(parityApi($this->token))->getJson(route($route, ['tab' => 'mentions']))->assertOk();
    auth()->forgetGuards();

    expect(collect($api->json('data'))->pluck($key === 'messages' ? 'conversation_id' : 'id')->all())->toBe([$mention->id]);
    TryPostServer::actingAs($this->user)->tool($tool, ['tab' => 'mentions'])
        ->assertOk()
        ->assertStructuredContent(parityMcpPage($key, $api));
})->with([
    'messages' => ['api.inbox.messages.index', ListInboxMessagesTool::class, 'messages'],
    'conversations' => ['api.inbox.conversations.index', ListInboxConversationsTool::class, 'conversations'],
]);

test('an unknown tab is refused on web, api and mcp', function () {
    $this->actingAs($this->user)->getJson(route('app.inbox.index', ['tab' => 'dms']))->assertUnprocessable();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.messages.index', ['tab' => 'dms']))->assertUnprocessable()->assertJsonValidationErrors('tab');
    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.resolutions.store'), ['scope' => 'all', 'tab' => 'dms'])->assertUnprocessable()->assertJsonValidationErrors('tab');
    TryPostServer::actingAs($this->user)->tool(ListInboxMessagesTool::class, ['tab' => 'dms'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, ['scope' => 'all', 'tab' => 'dms'])->assertHasErrors();
});

test('resolving all mentions leaves comments open on web, api and mcp', function (string $surface) {
    $mention = InboxConversation::factory()->create(['social_account_id' => $this->conversation->social_account_id, 'kind' => ConversationKind::Mention]);
    $mentions = InboxMessage::factory()->for($mention, 'conversation')->count(2)->create();
    $payload = ['scope' => 'all', 'tab' => 'mentions'];

    match ($surface) {
        'web' => $this->actingAs($this->user)->post(route('app.inbox.resolutions.store'), $payload)->assertRedirect(),
        'api' => $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.resolutions.store'), $payload)->assertOk()->assertJsonPath('resolved', 2),
        'mcp' => TryPostServer::actingAs($this->user)->tool(ResolveInboxMessagesTool::class, $payload)->assertOk()->assertSee('"resolved":2'),
    };

    expect($mentions->every(fn (InboxMessage $message): bool => filled($message->fresh()->resolved_at)))->toBeTrue()
        ->and($this->comment->fresh()->resolved_at)->toBeNull();
})->with(['web', 'api', 'mcp']);

test('a conversation page is the same on the api and mcp', function () {
    config()->set('app.pagination.default', 1);
    InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.show', [$this->conversation, 'page' => 2]))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetInboxConversationTool::class, ['conversation_id' => $this->conversation->id, 'page' => 2])
        ->assertOk()
        ->assertStructuredContent([
            ...collect($api->json())->except(['messages', 'meta'])->all(),
            'messages' => $api->json('messages'),
            'total' => $api->json('meta.total'),
            'per_page' => $api->json('meta.per_page'),
            'current_page' => $api->json('meta.current_page'),
            'last_page' => $api->json('meta.last_page'),
        ]);
});
