<?php

declare(strict_types=1);

use App\Http\Resources\App\InboxSavedRepliesResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('the index lists the workspace saved replies newest first', function () {
    $older = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subDay()]);
    $newer = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);
    WorkspaceSavedReply::factory()->create();

    $this->actingAs($this->user)->get(route('app.saved-replies.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('saved-replies/Index', false)
            ->where('hasData', true)
            ->has('savedReplies.data', 2)
            ->where('savedReplies.data.0.id', $newer->id)
            ->where('savedReplies.data.1.id', $older->id)
            ->where('filters.search', ''));
});

test('the first-use state comes from the database, not the search', function () {
    $this->actingAs($this->user)->get(route('app.saved-replies.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasData', false)->has('savedReplies.data', 0));

    WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Thanks', 'content' => 'Thank you!']);

    $this->actingAs($this->user)->get(route('app.saved-replies.index', ['search' => 'nothing matches']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasData', true)->has('savedReplies.data', 0)->where('filters.search', 'nothing matches'));
});

test('the search matches the name or the content', function () {
    $byName = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Shipping times', 'content' => 'We ship in 2 days.']);
    $byContent = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Refunds', 'content' => 'Refunds take a week, shipping excluded.']);
    WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Thanks', 'content' => 'Thank you!']);

    $this->actingAs($this->user)->get(route('app.saved-replies.index', ['search' => 'SHIPPING']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('savedReplies.data', 2)
            ->where('savedReplies.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$byName->id, $byContent->id])->sort()->values()->all()));
});

test('the index paginates with the app page size', function () {
    config()->set('app.pagination.default', 2);
    WorkspaceSavedReply::factory()->count(3)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get(route('app.saved-replies.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('savedReplies.data', 2));
});

test('a saved reply is created and the page is reloaded without a flash', function () {
    $this->actingAs($this->user)
        ->from(route('app.saved-replies.index'))
        ->post(route('app.saved-replies.store'), ['name' => 'Thanks', 'content' => 'Thanks, {first_name}!'])
        ->assertRedirect(route('app.saved-replies.index'))
        ->assertSessionMissing('flash.banner');

    $this->assertDatabaseHas('workspace_saved_replies', ['workspace_id' => $this->workspace->id, 'name' => 'Thanks', 'content' => 'Thanks, {first_name}!']);
});

test('the inbox picker creates a saved reply over JSON', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.saved-replies.store'), ['name' => 'Thanks', 'content' => 'Thank you!'])
        ->assertCreated()
        ->assertJsonPath('name', 'Thanks')
        ->assertJsonPath('content', 'Thank you!')
        ->assertJsonStructure(['id']);
});

test('a saved reply is updated', function () {
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->put(route('app.saved-replies.update', $savedReply), ['name' => 'Renamed', 'content' => 'New text'])
        ->assertRedirect();

    expect($savedReply->fresh()->only(['name', 'content']))->toBe(['name' => 'Renamed', 'content' => 'New text']);
});

test('a saved reply is deleted', function () {
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->delete(route('app.saved-replies.destroy', $savedReply))->assertRedirect();

    $this->assertSoftDeleted($savedReply);
});

test('the name and content are required and limited to 100 and 1000 characters', function (array $payload, string $field) {
    $this->actingAs($this->user)
        ->postJson(route('app.saved-replies.store'), [...['name' => 'Thanks', 'content' => 'Thank you!'], ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(WorkspaceSavedReply::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'missing content' => [['content' => ''], 'content'],
    'long content' => [['content' => str_repeat('a', 1001)], 'content'],
]);

test('the limits count characters, so 100 and 1000 emoji still fit', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.saved-replies.store'), ['name' => str_repeat('😀', 100), 'content' => str_repeat('😀', 1000)])
        ->assertCreated();
});

test('a saved reply of another workspace is not found', function () {
    $foreign = WorkspaceSavedReply::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.saved-replies.update', $foreign), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->actingAs($this->user)->delete(route('app.saved-replies.destroy', $foreign))->assertNotFound();

    expect($foreign->fresh()->name)->not->toBe('X')->and($foreign->fresh()->trashed())->toBeFalse();
});

test('a member who needs approval cannot see or manage saved replies', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->get(route('app.saved-replies.index'))->assertForbidden();
    $this->actingAs($member)->postJson(route('app.saved-replies.store'), ['name' => 'X', 'content' => 'Y'])->assertForbidden();
    $this->actingAs($member)->putJson(route('app.saved-replies.update', $savedReply), ['name' => 'X', 'content' => 'Y'])->assertForbidden();
    $this->actingAs($member)->delete(route('app.saved-replies.destroy', $savedReply))->assertForbidden();

    expect(WorkspaceSavedReply::query()->count())->toBe(1);
});

test('a member who publishes directly manages saved replies', function () {
    $member = workspaceMember($this->workspace, 'member');

    $this->actingAs($member)->get(route('app.saved-replies.index'))->assertOk();
    $this->actingAs($member)->postJson(route('app.saved-replies.store'), ['name' => 'X', 'content' => 'Y'])->assertCreated();
});

test('saved replies do not exist while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get(route('app.saved-replies.index'))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('app.saved-replies.store'), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->actingAs($this->user)->putJson(route('app.saved-replies.update', $savedReply), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->actingAs($this->user)->delete(route('app.saved-replies.destroy', $savedReply))->assertNotFound();

    expect(WorkspaceSavedReply::query()->count())->toBe(1);
});

test('the inbox page carries the saved replies as a once prop for members who can reply', function () {
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Thanks', 'content' => 'Thanks, {first_name}!']);
    WorkspaceSavedReply::factory()->create();

    $this->actingAs($this->user->fresh())->get(route('app.inbox.messages.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('savedReplies', [['id' => $savedReply->id, 'name' => 'Thanks', 'content' => 'Thanks, {first_name}!']]));

    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($member)->get(route('app.inbox.messages.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('savedReplies', []));
});

test('the saved replies once prop key changes on every create, update and delete', function () {
    $keys = [InboxSavedRepliesResource::key($this->workspace)];

    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id]);
    $keys[] = InboxSavedRepliesResource::key($this->workspace);

    $savedReply->update(['name' => 'Renamed']);
    $keys[] = InboxSavedRepliesResource::key($this->workspace);

    $savedReply->delete();
    $keys[] = InboxSavedRepliesResource::key($this->workspace);

    expect(array_unique($keys))->toHaveCount(4);
});
