<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Enums\Inbox\DeliveryStatus;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->travelTo('2026-10-16 12:00:00');
    fakeDirectMessageDriver();
    $this->api = createApiTestToken();
    $this->headers = ['Authorization' => "Bearer {$this->api['plain_token']}", 'Accept' => 'application/json'];
    $this->server = ['HTTP_HOST' => 'api.trypost.test'];
    $this->workspace = $this->api['workspace'];
    $this->account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(['workspace_id' => $this->workspace->id]);
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    $this->dm = InboxConversation::query()->sole();
    $this->owner = $this->api['user'];
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
    $this->send = fn (array $payload, ?InboxConversation $conversation = null) => $this->withHeaders($this->headers)
        ->postJson(route('api.inbox.conversations.messages.store', $conversation ?? $this->dm), $payload, $this->server);
});

test('the API sends a DM', function () {
    ($this->send)(['text' => 'Thanks!'])
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonPath('delivery_status', DeliveryStatus::Sending->value)
        ->assertJsonPath('direction', 'outbound')
        ->assertJsonPath('body', 'Thanks!');
});

test('the API refuses a message after the window', function () {
    $this->travelTo('2026-10-17 10:00:01');

    ($this->send)(['text' => 'Late'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors('text');
    expect(InboxMessage::query()->count())->toBe(1);
});

test('the API refuses blank text', function () {
    ($this->send)(['text' => '   '])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('the API shows the reply window on the conversation', function () {
    $this->withHeaders($this->headers)
        ->getJson(route('api.inbox.conversations.index', ['tab' => 'messages']), $this->server)
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->dm->id)
        ->assertJsonPath('data.0.reply_window_ends_at', '2026-10-17T10:00:00+00:00')
        ->assertJsonPath('data.0.can_send_message', true)
        ->assertJsonPath('data.0.last_message.body', 'Hi there');
});

test('a comment conversation answers 404', function () {
    UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));
    $comments = InboxConversation::query()->where('kind', 'comment')->sole();

    ($this->send)(['text' => 'Hi'], $comments)->assertNotFound();
});

test('a member who needs approval cannot send through the API', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $headers = ['Authorization' => 'Bearer '.passportToken($member, $this->workspace), 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->postJson(route('api.inbox.conversations.messages.store', $this->dm), ['text' => 'Hi'], $this->server)
        ->assertForbidden();
});

describe('web', function () {
    test('the web endpoint sends a DM and goes back', function () {
        $this->actingAs($this->owner)
            ->from(route('app.inbox.conversations.show', $this->dm))
            ->post(route('app.inbox.conversations.messages.store', $this->dm), ['text' => 'Thanks!'])
            ->assertRedirect(route('app.inbox.conversations.show', $this->dm));

        expect(InboxMessage::query()->where('direction', 'outbound')->value('body'))->toBe('Thanks!');
    });

    test('a member who needs approval cannot send', function () {
        $this->actingAs(workspaceMember($this->workspace, 'approval'))
            ->post(route('app.inbox.conversations.messages.store', $this->dm), ['text' => 'Hi'])
            ->assertForbidden();
    });

    test('a message past the limit goes back with the error', function () {
        $this->actingAs($this->owner)
            ->post(route('app.inbox.conversations.messages.store', $this->dm), ['text' => str_repeat('😀', 300)])
            ->assertSessionHasErrors('text');
    });

    test('a comment conversation answers 404', function () {
        UpsertInboxMessage::execute($this->account, inboxIncoming('c1'));

        $this->actingAs($this->owner)
            ->post(route('app.inbox.conversations.messages.store', InboxConversation::query()->where('kind', 'comment')->sole()), ['text' => 'Hi'])
            ->assertNotFound();
    });
});

test('showing a DM conversation over the API carries its last message', function () {
    $this->withHeaders($this->headers)->getJson(route('api.inbox.conversations.show', $this->dm), $this->server)
        ->assertOk()
        ->assertJsonPath('last_message.id', InboxMessage::query()->sole()->id);
});
