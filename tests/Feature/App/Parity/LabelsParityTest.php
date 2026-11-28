<?php

declare(strict_types=1);

use App\Actions\Label\ListLabels;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\DeleteLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Models\WorkspaceLabel;
use App\Support\Requests\Label\LabelRequestRules;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('a label created through the api and mcp is stored the same way and rejects the same bad color', function () {
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->postJson(route('api.labels.store'), ['name' => 'Launch', 'color' => '#FF5733'])->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreateLabelTool::class, ['name' => 'Launch', 'color' => '#FF5733'])->assertOk();

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.labels.store'), ['name' => 'Bad', 'color' => 'red'])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(CreateLabelTool::class, ['name' => 'Bad', 'color' => 'red'])->assertHasErrors();

    expect(WorkspaceLabel::query()->where('workspace_id', $this->workspace->id)->pluck('color')->all())->toBe(['#FF5733', '#FF5733']);
});

test('the web, api and mcp label rules refuse the same names and colors', function () {
    foreach ([['name' => '', 'color' => '#FF5733'], ['name' => str_repeat('a', 256), 'color' => '#FF5733'], ['name' => 'Ok', 'color' => '#FFF'], ['name' => 'Ok', 'color' => '']] as $payload) {
        $this->actingAs($this->user)->postJson(route('app.labels.store'), $payload)->assertUnprocessable();
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->postJson(route('api.labels.store'), $payload)->assertUnprocessable();
        TryPostServer::actingAs($this->user)->tool(CreateLabelTool::class, $payload)->assertHasErrors();
    }

    expect(WorkspaceLabel::query()->count())->toBe(0);
});

test('updating a label through the api and mcp stores the same name and color', function () {
    $first = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.labels.update', $first), ['name' => 'Renamed', 'color' => '#00AA11'])->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdateLabelTool::class, ['label_id' => $second->id, 'name' => 'Renamed', 'color' => '#00AA11'])->assertOk();

    expect($first->fresh()->only(['name', 'color']))->toBe($second->fresh()->only(['name', 'color']));
});

test('deleting a label through the api and mcp removes it', function () {
    $first = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.labels.destroy', $first))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteLabelTool::class, ['label_id' => $second->id])->assertOk();

    expect(WorkspaceLabel::query()->count())->toBe(0);
});

test('a label of another workspace is refused with a 404 on the api and an error on mcp', function () {
    $foreign = WorkspaceLabel::factory()->create();

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.labels.update', $foreign), ['name' => 'X', 'color' => '#00AA11'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.labels.destroy', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(UpdateLabelTool::class, ['label_id' => $foreign->id, 'name' => 'X', 'color' => '#00AA11'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(DeleteLabelTool::class, ['label_id' => $foreign->id])->assertHasErrors();

    expect($foreign->fresh())->not->toBeNull();
});

test('listing labels returns the same resource fields on the api and mcp', function () {
    WorkspaceLabel::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.labels.index'))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListLabelsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('labels', $api));
    expect(array_keys($api->json('data.0')))->toBe(['id', 'name', 'color', 'created_at', 'updated_at']);
});

test('the api and mcp label lists paginate with the app default like the web list', function () {
    config()->set('app.pagination.default', 2);
    WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->startOfSecond()]);
    $expected = ListLabels::execute($this->workspace)->pluck('id')->all();

    $first = $this->withHeaders(parityApi($this->token))->getJson(route('api.labels.index'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route('api.labels.index', ['page' => 2, 'per_page' => 50]))->assertOk()->assertJsonCount(1, 'data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListLabelsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('labels', $first));
    TryPostServer::actingAs($this->user)->tool(ListLabelsTool::class, ['page' => 2])->assertOk()->assertStructuredContent(parityMcpPage('labels', $second));

    expect([...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')])->toBe($expected);
});

test('a member who is not an admin manages labels through mcp but the whole api refuses them', function () {
    $member = workspaceMember($this->workspace, 'member');
    $memberToken = passportToken($member, $this->workspace);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($memberToken))->postJson(route('api.labels.store'), ['name' => 'Nope', 'color' => '#FF5733'])->assertStatus(Response::HTTP_FORBIDDEN);
    TryPostServer::actingAs($member)->tool(CreateLabelTool::class, ['name' => 'Yes', 'color' => '#FF5733'])->assertOk();

    expect(WorkspaceLabel::query()->pluck('name')->all())->toBe(['Yes']);
});

test('the api, mcp and web label lists share one query and order', function () {
    $labels = WorkspaceLabel::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->startOfSecond()]);
    $expected = ListLabels::execute($this->workspace)->pluck('id')->all();

    $apiResponse = $this->withHeaders(parityApi($this->token))->getJson(route('api.labels.index'))->assertOk();
    $api = collect($apiResponse->json('data'))->pluck('id')->all();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListLabelsTool::class)->assertOk()->assertStructuredContent(parityMcpPage('labels', $apiResponse));

    expect($expected)->toHaveCount(3)->and($api)->toBe($expected)
        ->and($expected)->toBe($labels->pluck('id')->sortDesc()->values()->all());
});

test('a bad label color gets the same message on the api and mcp from the shared rules', function () {
    $message = $this->withHeaders(parityApi($this->token))->postJson(route('api.labels.store'), ['name' => 'Bad', 'color' => 'red'])->assertUnprocessable()->json('errors.color.0');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreateLabelTool::class, ['name' => 'Bad', 'color' => 'red'])->assertHasErrors([$message]);

    expect(array_keys(LabelRequestRules::rules()))->toBe(['name', 'color']);
});
