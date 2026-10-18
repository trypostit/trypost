<?php

declare(strict_types=1);

use App\Actions\SavedReply\ListSavedReplies;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\SavedReply\CreateSavedReplyTool;
use App\Mcp\Tools\SavedReply\DeleteSavedReplyTool;
use App\Mcp\Tools\SavedReply\ListSavedRepliesTool;
use App\Mcp\Tools\SavedReply\UpdateSavedReplyTool;
use App\Models\WorkspaceSavedReply;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.inbox.enabled', true);
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('a saved reply created on the web, the api and mcp is stored the same way', function () {
    $payload = ['name' => 'Thanks', 'content' => 'Thanks, {first_name}!'];

    $this->actingAs($this->user)->postJson(route('app.saved-replies.store'), $payload)->assertCreated();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.saved-replies.store'), $payload)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, $payload)->assertOk();

    expect(WorkspaceSavedReply::query()->get()->map->only(['name', 'content'])->all())->toBe([$payload, $payload, $payload]);
});

test('the web, api and mcp refuse the same payloads with the same message', function (array $payload, string $field) {
    $web = $this->actingAs($this->user)->postJson(route('app.saved-replies.store'), $payload)->assertUnprocessable()->json("errors.{$field}.0");
    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))->postJson(route('api.saved-replies.store'), $payload)->assertUnprocessable()->json("errors.{$field}.0");
    TryPostServer::actingAs($this->user)->tool(CreateSavedReplyTool::class, $payload)->assertHasErrors([$web]);

    expect($api)->toBe($web)->and(WorkspaceSavedReply::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => '', 'content' => 'x'], 'name'],
    'name over 100' => [['name' => str_repeat('a', 101), 'content' => 'x'], 'name'],
    'missing content' => [['name' => 'Ok', 'content' => ''], 'content'],
    'content over 1000' => [['name' => 'Ok', 'content' => str_repeat('a', 1001)], 'content'],
]);

test('updating and deleting through the web, api and mcp give the same result', function () {
    [$web, $api, $mcp] = WorkspaceSavedReply::factory()->count(3)->create(['workspace_id' => $this->workspace->id])->all();
    $payload = ['name' => 'Renamed', 'content' => 'New text'];

    $this->actingAs($this->user)->put(route('app.saved-replies.update', $web), $payload)->assertRedirect();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.saved-replies.update', $api), $payload)->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdateSavedReplyTool::class, ['saved_reply_id' => $mcp->id, ...$payload])->assertOk();

    expect([$web->fresh()->only(['name', 'content']), $api->fresh()->only(['name', 'content']), $mcp->fresh()->only(['name', 'content'])])->toBe([$payload, $payload, $payload]);

    $this->actingAs($this->user)->delete(route('app.saved-replies.destroy', $web))->assertRedirect();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.saved-replies.destroy', $api))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteSavedReplyTool::class, ['saved_reply_id' => $mcp->id])->assertOk();

    expect(WorkspaceSavedReply::query()->count())->toBe(0);
});

test('the web, api and mcp lists share one query, order, search and page size', function () {
    config()->set('app.pagination.default', 2);
    WorkspaceSavedReply::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'name' => 'Shipping', 'created_at' => now()->startOfSecond()]);
    WorkspaceSavedReply::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Other', 'content' => 'Nothing']);
    $expected = ListSavedReplies::execute($this->workspace, 'shipping')->limit(2)->pluck('id')->all();

    $web = $this->actingAs($this->user)->get(route('app.saved-replies.index', ['search' => 'shipping']));
    $web->assertInertia(fn (AssertableInertia $page) => $page->where('savedReplies.data', fn ($rows) => collect($rows)->pluck('id')->all() === $expected));
    auth()->forgetGuards();

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.saved-replies.index', ['search' => 'shipping']))->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
    TryPostServer::actingAs($this->user)->tool(ListSavedRepliesTool::class, ['search' => 'shipping'])->assertOk()->assertStructuredContent(parityMcpPage('saved_replies', $api));

    expect(collect($api->json('data'))->pluck('id')->all())->toBe($expected)
        ->and(array_keys($api->json('data.0')))->toBe(['id', 'name', 'content', 'created_at', 'updated_at']);
});

test('a saved reply of another workspace is not found on the web, api and mcp', function () {
    $foreign = WorkspaceSavedReply::factory()->create();

    $this->actingAs($this->user)->putJson(route('app.saved-replies.update', $foreign), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.saved-replies.destroy', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(DeleteSavedReplyTool::class, ['saved_reply_id' => $foreign->id])->assertHasErrors(['Saved reply not found.']);

    expect($foreign->fresh()->trashed())->toBeFalse();
});
