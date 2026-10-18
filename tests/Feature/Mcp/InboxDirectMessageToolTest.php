<?php

declare(strict_types=1);

use App\Actions\Inbox\UpsertInboxMessage;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Inbox\ListInboxConversationsTool;
use App\Mcp\Tools\Inbox\SendInboxDirectMessageTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    Queue::fake();
    $this->travelTo('2026-10-16 12:00:00');
    fakeDirectMessageDriver();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->instagram()->withDirectMessageScopes()->create(['workspace_id' => $this->workspace->id]);
    UpsertInboxMessage::execute($this->account, inboxDirectIncoming('m1', at: '2026-10-16 10:00:00'));
    $this->dm = InboxConversation::query()->sole();
});

test('the tool sends a DM', function () {
    TryPostServer::actingAs($this->user)->tool(SendInboxDirectMessageTool::class, [
        'conversation_id' => $this->dm->id,
        'text' => 'Thanks!',
    ])->assertOk()->assertStructuredContent(fn (AssertableJson $json) => $json
        ->where('delivery_status', 'sending')
        ->where('direction', 'outbound')
        ->where('body', 'Thanks!')
        ->etc());
});

test('the tool refuses after the window, outside the workspace and for members who need approval', function () {
    $this->travelTo('2026-10-17 10:00:01');
    TryPostServer::actingAs($this->user)->tool(SendInboxDirectMessageTool::class, ['conversation_id' => $this->dm->id, 'text' => 'Late'])->assertHasErrors();

    $outsider = User::factory()->create();
    $other = Workspace::factory()->create(['user_id' => $outsider->id]);
    $other->members()->attach($outsider->id, membershipPivot('member'));
    $outsider->update(['current_workspace_id' => $other->id]);
    TryPostServer::actingAs($outsider)->tool(SendInboxDirectMessageTool::class, ['conversation_id' => $this->dm->id, 'text' => 'Hi'])->assertHasErrors(['Inbox conversation not found.']);

    $this->travelTo('2026-10-16 12:00:00');
    TryPostServer::actingAs(workspaceMember($this->workspace, 'approval'))->tool(SendInboxDirectMessageTool::class, ['conversation_id' => $this->dm->id, 'text' => 'Hi'])->assertHasErrors();

    expect(InboxMessage::query()->where('direction', 'outbound')->exists())->toBeFalse();
});

test('the list tool reads the Messages tab', function () {
    TryPostServer::actingAs($this->user)->tool(ListInboxConversationsTool::class, ['tab' => 'messages'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('conversations', 1, fn (AssertableJson $conversation) => $conversation
                ->where('id', $this->dm->id)
                ->where('can_send_message', true)
                ->where('last_message.body', 'Hi there')
                ->etc())
            ->etc());
});
