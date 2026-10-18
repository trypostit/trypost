<?php

declare(strict_types=1);

use App\Enums\Inbox\DeliveryStatus;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->api = createApiTestToken();
    $this->headers = ['Authorization' => "Bearer {$this->api['plain_token']}", 'Accept' => 'application/json'];
    $this->server = ['HTTP_HOST' => 'api.trypost.test'];
    $account = SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->api['workspace']->id]);
    $this->conversation = InboxConversation::factory()->create(['social_account_id' => $account->id]);
    $this->comment = InboxMessage::factory()->for($this->conversation, 'conversation')->create();
});

test('list conversations', function () {
    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.index'), $this->server)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->conversation->id)
        ->assertJsonPath('meta.per_page', (int) config('app.pagination.default'));
});

test('list messages', function () {
    $this->withHeaders($this->headers)->getJson(route('api.inbox.messages.index'), $this->server)
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->comment->id);
});

test('every inbox endpoint is a 404 when the module is off', function (string $method, string $name, string $binding, array $payload) {
    Queue::fake([SendInboxReply::class]);
    config()->set('trypost.inbox.enabled', false);
    $parameters = match ($binding) {
        'conversation' => [$this->conversation],
        'message' => [$this->comment],
        default => [],
    };

    $this->withHeaders($this->headers)->json($method, route($name, $parameters), $payload, $this->server)->assertNotFound();
    Queue::assertNothingPushed();
})->with([
    'list conversations' => ['GET', 'api.inbox.conversations.index', '', []],
    'list messages' => ['GET', 'api.inbox.messages.index', '', []],
    'show' => ['GET', 'api.inbox.conversations.show', 'conversation', []],
    'direct message' => ['POST', 'api.inbox.conversations.messages.store', 'conversation', ['text' => 'hi']],
    'reply' => ['POST', 'api.inbox.replies.store', 'message', ['text' => 'hi']],
    'retry' => ['POST', 'api.inbox.replies.retry', 'message', []],
    'resolve' => ['POST', 'api.inbox.resolutions.store', '', ['scope' => 'all']],
    'unresolve' => ['DELETE', 'api.inbox.resolutions.destroy', 'message', []],
]);

test('show a conversation with its messages', function () {
    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', $this->conversation), $this->server)
        ->assertOk()
        ->assertJsonPath('messages.0.id', $this->comment->id)
        ->assertJsonPath('meta.per_page', (int) config('app.pagination.default'))
        ->assertJsonPath('meta.total', 1);
});

test('show pages the conversation comments with the app page size', function () {
    config()->set('app.pagination.default', 1);
    $newer = InboxMessage::factory()->for($this->conversation, 'conversation')->create(['platform_created_at' => now()]);

    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', [$this->conversation, 'page' => 2]), $this->server)
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.id', $this->comment->id)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);

    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', $this->conversation), $this->server)
        ->assertJsonPath('messages.0.id', $newer->id);
});

test('reply returns the sending message', function () {
    Queue::fake([SendInboxReply::class]);

    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.store', $this->comment), ['text' => 'thanks!'], $this->server)
        ->assertCreated()
        ->assertJsonPath('delivery_status', 'sending')
        ->assertJsonPath('body', 'thanks!');
});

test('reply validates the text', function () {
    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.store', $this->comment), ['text' => '   '], $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('text');
});

test('a member who needs approval cannot reply or resolve', function () {
    $user = User::factory()->create(['account_id' => $this->api['workspace']->account_id]);
    $this->api['workspace']->members()->attach($user->id, membershipPivot('approval'));
    $user->update(['current_workspace_id' => $this->api['workspace']->id]);
    $headers = ['Authorization' => 'Bearer '.passportToken($user, $this->api['workspace']), 'Accept' => 'application/json'];

    $this->withHeaders($headers)->postJson(route('api.inbox.replies.store', $this->comment), ['text' => 'thanks!'], $this->server)->assertForbidden();
    $this->withHeaders($headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'message', 'message_id' => $this->comment->id], $this->server)->assertForbidden();
    $this->withHeaders($headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'all'], $this->server)->assertForbidden();
    $this->withHeaders($headers)->deleteJson(route('api.inbox.resolutions.destroy', $this->comment), [], $this->server)->assertForbidden();
});

test('another workspace is a 404 everywhere', function () {
    $foreign = InboxConversation::factory()->create();
    $foreignMessage = InboxMessage::factory()->for($foreign, 'conversation')->create();

    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', $foreign), $this->server)->assertNotFound();
    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.store', $foreignMessage), ['text' => 'hi'], $this->server)->assertNotFound();
    $this->withHeaders($this->headers)->deleteJson(route('api.inbox.resolutions.destroy', $foreignMessage), [], $this->server)->assertNotFound();
    $this->withHeaders($this->headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'message', 'message_id' => $foreignMessage->id], $this->server)->assertNotFound();
    $this->withHeaders($this->headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'conversation', 'conversation_id' => $foreign->id], $this->server)->assertNotFound();
});

test('resolve and undo', function () {
    $this->withHeaders($this->headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'message', 'message_id' => $this->comment->id], $this->server)
        ->assertOk()
        ->assertJsonPath('resolved', 1);

    $this->withHeaders($this->headers)->deleteJson(route('api.inbox.resolutions.destroy', $this->comment), [], $this->server)
        ->assertOk()
        ->assertJsonPath('resolved_at', null);
});

test('resolve everything of the workspace', function () {
    $this->withHeaders($this->headers)->postJson(route('api.inbox.resolutions.store'), ['scope' => 'all'], $this->server)
        ->assertOk()
        ->assertJsonPath('resolved', 1);
});

test('invalid resolution ids fail validation instead of erroring', function (array $payload, string $field) {
    $this->withHeaders($this->headers)->postJson(route('api.inbox.resolutions.store'), $payload, $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'malformed message id' => [['scope' => 'message', 'message_id' => 'abc'], 'message_id'],
    'array message id' => [['scope' => 'message', 'message_id' => ['x']], 'message_id'],
    'missing message id' => [['scope' => 'message'], 'message_id'],
    'malformed conversation id' => [['scope' => 'conversation', 'conversation_id' => 'abc'], 'conversation_id'],
    'malformed channel' => [['scope' => 'all', 'channels' => ['abc']], 'channels.0'],
    'channels not a list' => [['scope' => 'all', 'channels' => 'abc'], 'channels'],
]);

test('retry sends a failed reply again', function () {
    Queue::fake([SendInboxReply::class]);
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'delivery_status' => DeliveryStatus::Failed, 'delivery_error' => 'nope']);

    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.retry', $failed), [], $this->server)
        ->assertOk()
        ->assertJsonPath('id', $failed->id)
        ->assertJsonPath('delivery_status', 'sending')
        ->assertJsonPath('delivery_error', null);

    Queue::assertPushed(SendInboxReply::class);
});

test('retry refuses a reply that did not fail and another workspace', function () {
    $foreign = InboxMessage::factory()->outbound()->create(['delivery_status' => DeliveryStatus::Failed]);

    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.retry', $this->comment), [], $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('message');
    $this->withHeaders($this->headers)->postJson(route('api.inbox.replies.retry', $foreign), [], $this->server)->assertNotFound();
});

test('the lists filter by several channels', function () {
    $second = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->api['workspace']->id])->id]);
    InboxMessage::factory()->for($second, 'conversation')->create();
    $third = InboxConversation::factory()->create(['social_account_id' => SocialAccount::factory()->bluesky()->create(['workspace_id' => $this->api['workspace']->id])->id]);
    InboxMessage::factory()->for($third, 'conversation')->create();
    $channels = ['channels' => [$this->conversation->social_account_id, $second->social_account_id]];

    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.index', $channels), $this->server)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['id' => $third->id]);

    $this->withHeaders($this->headers)->getJson(route('api.inbox.messages.index', $channels), $this->server)
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.index'), $this->server)
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('the lists refuse a channel of another workspace', function (string $route) {
    $foreign = SocialAccount::factory()->bluesky()->create();

    $this->withHeaders($this->headers)->getJson(route($route, ['channels' => [$foreign->id]]), $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.0');
})->with(['api.inbox.conversations.index', 'api.inbox.messages.index']);

test('the lists refuse a channel without an inbox', function (string $route) {
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $this->api['workspace']->id]);

    $this->withHeaders($this->headers)->getJson(route($route, ['channels' => [$pinterest->id]]), $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('channels.0');
})->with(['api.inbox.conversations.index', 'api.inbox.messages.index']);

test('showing a page below one fails validation', function () {
    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', [$this->conversation, 'page' => 0]), $this->server)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');
});

test('a member who needs approval cannot retry a reply, refused by the API before the inbox', function () {
    $failed = InboxMessage::factory()->outbound()->for($this->conversation, 'conversation')->create(['parent_id' => $this->comment->id, 'delivery_status' => DeliveryStatus::Failed]);
    $user = workspaceMember($this->api['workspace'], 'approval');
    $token = passportToken($user, $this->api['workspace']);
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];

    $this->withHeaders($headers)->postJson(route('api.inbox.replies.retry', $failed), [], $this->server)
        ->assertForbidden()
        ->assertJsonPath('message', 'Insufficient workspace permissions.');
    expect($failed->fresh()->delivery_status)->toBe(DeliveryStatus::Failed);
});
