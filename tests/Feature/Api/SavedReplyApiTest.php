<?php

declare(strict_types=1);

use App\Models\WorkspaceSavedReply;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    $this->api = createApiTestToken();
    $this->headers = ['Authorization' => "Bearer {$this->api['plain_token']}"];
});

test('list saved replies newest first', function () {
    $older = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id, 'created_at' => now()->subDay()]);
    $newer = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id]);
    WorkspaceSavedReply::factory()->create();

    $this->withHeaders($this->headers)->getJson(route('api.saved-replies.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('meta.per_page', (int) config('app.pagination.default'));
});

test('search saved replies by name or content', function () {
    WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id, 'name' => 'Refunds', 'content' => 'We refund in a week.']);
    $match = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id, 'name' => 'Thanks', 'content' => 'Thank you for the kind words!']);

    $this->withHeaders($this->headers)->getJson(route('api.saved-replies.index', ['search' => 'kind words']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

test('create a saved reply', function () {
    $this->withHeaders($this->headers)->postJson(route('api.saved-replies.store'), ['name' => 'Thanks', 'content' => 'Thanks, {first_name}!'])
        ->assertCreated()
        ->assertJsonPath('name', 'Thanks')
        ->assertJsonPath('content', 'Thanks, {first_name}!');

    expect($this->api['workspace']->savedReplies()->count())->toBe(1);
});

test('create rejects a name over 100 and a content over 1000 characters', function () {
    $this->withHeaders($this->headers)->postJson(route('api.saved-replies.store'), ['name' => str_repeat('a', 101), 'content' => str_repeat('a', 1001)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'content']);
});

test('update a saved reply', function () {
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id]);

    $this->withHeaders($this->headers)->putJson(route('api.saved-replies.update', $savedReply), ['name' => 'Renamed', 'content' => 'New'])
        ->assertOk()
        ->assertJsonPath('name', 'Renamed');
});

test('delete a saved reply', function () {
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id]);

    $this->withHeaders($this->headers)->deleteJson(route('api.saved-replies.destroy', $savedReply))->assertNoContent();

    $this->assertSoftDeleted($savedReply);
});

test('a saved reply of another workspace is not found', function () {
    $foreign = WorkspaceSavedReply::factory()->create();

    $this->withHeaders($this->headers)->putJson(route('api.saved-replies.update', $foreign), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->withHeaders($this->headers)->deleteJson(route('api.saved-replies.destroy', $foreign))->assertNotFound();

    expect($foreign->fresh()->trashed())->toBeFalse();
});

test('the saved replies api does not exist while the inbox is off', function () {
    config()->set('trypost.inbox.enabled', false);
    $savedReply = WorkspaceSavedReply::factory()->create(['workspace_id' => $this->api['workspace']->id]);

    $this->withHeaders($this->headers)->getJson(route('api.saved-replies.index'))->assertNotFound();
    $this->withHeaders($this->headers)->postJson(route('api.saved-replies.store'), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->withHeaders($this->headers)->putJson(route('api.saved-replies.update', $savedReply), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    $this->withHeaders($this->headers)->deleteJson(route('api.saved-replies.destroy', $savedReply))->assertNotFound();

    expect(WorkspaceSavedReply::query()->count())->toBe(1);
});
