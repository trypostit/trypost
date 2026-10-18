<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SavedReply\CreateSavedReplyTool;
use App\Mcp\Tools\SavedReply\DeleteSavedReplyTool;
use App\Mcp\Tools\SavedReply\ListSavedRepliesTool;
use App\Mcp\Tools\SavedReply\UpdateSavedReplyTool;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
});

test('list saved replies with search and pagination', function () {
    config()->set('app.pagination.default', 1);
    WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Refunds', 'content' => 'A week.']);
    $match = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Thanks', 'content' => 'Thank you!']);

    TryPostServer::actingAs($this->user)->tool(ListSavedRepliesTool::class, ['search' => 'thank'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->has('saved_replies', 1)
            ->where('saved_replies.0.id', $match->id)
            ->where('total', 1)
            ->etc());

    TryPostServer::actingAs($this->user)->tool(ListSavedRepliesTool::class, ['page' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json->has('saved_replies', 1)->where('total', 2)->where('last_page', 2)->etc());
});

test('create, update and delete a saved reply', function () {
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, ['name' => 'Thanks', 'content' => 'Thanks, {first_name}!'])->assertOk();

    $savedReply = WorkspaceSavedReply::query()->sole();
    expect($savedReply->only(['name', 'content']))->toBe(['name' => 'Thanks', 'content' => 'Thanks, {first_name}!']);

    TryPostServer::actingAs($this->user)->tool(UpdateSavedReplyTool::class, ['saved_reply_id' => $savedReply->id, 'name' => 'Renamed', 'content' => 'New'])->assertOk();
    expect($savedReply->fresh()->name)->toBe('Renamed');

    TryPostServer::actingAs($this->user)->tool(DeleteSavedReplyTool::class, ['saved_reply_id' => $savedReply->id])->assertOk();
    $this->assertSoftDeleted($savedReply);
});

test('create refuses a name over 100 and a content over 1000 characters', function () {
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, ['name' => str_repeat('a', 101), 'content' => 'x'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, ['name' => 'Ok', 'content' => str_repeat('a', 1001)])->assertHasErrors();

    expect(WorkspaceSavedReply::query()->count())->toBe(0);
});

test('a saved reply of another workspace is not found', function () {
    $foreign = WorkspaceSavedReply::factory()->create();

    TryPostServer::actingAs($this->user)->tool(UpdateSavedReplyTool::class, ['saved_reply_id' => $foreign->id, 'name' => 'X', 'content' => 'Y'])->assertHasErrors(['Saved reply not found.']);
    TryPostServer::actingAs($this->user)->tool(DeleteSavedReplyTool::class, ['saved_reply_id' => $foreign->id])->assertHasErrors(['Saved reply not found.']);

    expect($foreign->fresh()->trashed())->toBeFalse();
});

test('a member who needs approval is refused', function () {
    $member = workspaceMember($this->workspace, 'approval');

    TryPostServer::actingAs($member)->tool(ListSavedRepliesTool::class)->assertHasErrors();
    TryPostServer::actingAs($member)->tool(CreateSavedReplyTool::class, ['name' => 'X', 'content' => 'Y'])->assertHasErrors();

    expect(WorkspaceSavedReply::query()->count())->toBe(0);
});

test('the tools refuse while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(ListSavedRepliesTool::class)->assertHasErrors(['The inbox is not enabled.']);
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, ['name' => 'X', 'content' => 'Y'])->assertHasErrors(['The inbox is not enabled.']);
    TryPostServer::actingAs($this->user)->tool(UpdateSavedReplyTool::class, ['saved_reply_id' => $savedReply->id, 'name' => 'X', 'content' => 'Y'])->assertHasErrors(['The inbox is not enabled.']);
    TryPostServer::actingAs($this->user)->tool(DeleteSavedReplyTool::class, ['saved_reply_id' => $savedReply->id])->assertHasErrors(['The inbox is not enabled.']);

    expect($savedReply->fresh()->only(['name', 'deleted_at']))->toBe(['name' => $savedReply->name, 'deleted_at' => null]);
});
