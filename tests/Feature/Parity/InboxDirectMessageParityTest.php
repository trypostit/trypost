<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\MessageDirection;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\ListInboxConversationsTool;
use App\Mcp\Tools\Inbox\SendInboxDirectMessageTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->travelTo('2026-10-16 12:00:00');
    fakeDirectMessageDriver();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(['workspace_id' => $this->workspace->id]);
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    UpsertInboxMessage::execute($account, inboxDirectIncoming('m2', at: '2026-10-16 09:00:00', participant: 'igsid-2'));
    UpsertInboxMessage::execute($account, inboxIncoming('c1'));
    $this->dm = InboxConversation::query()->where('platform_conversation_id', 'igsid-1')->sole();
});

test('the three surfaces list the same DM conversations', function () {
    $web = [];
    $this->actingAs($this->user)->get(route('app.inbox.index', ['tab' => 'messages']))
        ->assertInertia(function (AssertableInertia $page) use (&$web) {
            $web = collect($page->toArray()['props']['conversations']['data'])->pluck('id')->all();
        });
    auth()->forgetGuards();

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.index', ['tab' => 'messages']))->assertOk();
    auth()->forgetGuards();

    expect($web)->toHaveCount(2)
        ->and(collect($api->json('data'))->pluck('id')->all())->toEqual($web);

    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, ['tab' => 'messages'])
        ->assertOk()
        ->assertStructuredContent(parityMcpPage('conversations', $api));
});

test('the three surfaces refuse the same message', function (string $text, ?string $at) {
    if ($at) {
        $this->travelTo($at);
    }

    $this->actingAs($this->user)->post(route('app.inbox.conversations.messages.store', $this->dm), ['text' => $text])->assertSessionHasErrors('text');
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.conversations.messages.store', $this->dm), ['text' => $text])->assertUnprocessable()->assertJsonValidationErrors('text');
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(SendInboxDirectMessageTool::class, ['conversation_id' => $this->dm->id, 'text' => $text])->assertHasErrors();

    expect(InboxMessage::query()->where('direction', 'outbound')->exists())->toBeFalse();
})->with([
    'blank' => ['   ', null],
    'over the byte limit' => [str_repeat('😀', 300), null],
    'after the window' => ['Late', '2026-10-17 10:00:01'],
]);

test('the three surfaces refuse a conversation the person never wrote in', function () {
    UpsertInboxMessage::execute($this->dm->socialAccount, inboxDirectIncoming('echo-1', MessageDirection::Outbound, '2026-10-16 11:00:00', participant: 'igsid-echo'));
    $echoOnly = InboxConversation::query()->where('platform_conversation_id', 'igsid-echo')->sole();

    $this->actingAs($this->user)->post(route('app.inbox.conversations.messages.store', $echoOnly), ['text' => 'Hi'])->assertSessionHasErrors('text');
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.inbox.conversations.messages.store', $echoOnly), ['text' => 'Hi'])->assertUnprocessable()->assertJsonValidationErrors('text');
    $this->withHeaders(parityApi($this->token))->getJson(route('api.inbox.conversations.show', $echoOnly))->assertOk()->assertJsonPath('can_send_message', false);
    auth()->forgetGuards();
    TryPostServer::actingAs($this->user)->tool(SendInboxDirectMessageTool::class, ['conversation_id' => $echoOnly->id, 'text' => 'Hi'])->assertHasErrors();

    expect(InboxMessage::query()->where('direction', 'outbound')->count())->toBe(1);
});
