<?php

declare(strict_types=1);

use App\Actions\Signature\ListSignatures;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\DeleteSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\Signature\UpdateSignatureTool;
use App\Models\WorkspaceSignature;
use App\Support\Requests\Signature\SignatureRequestRules;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('a signature created through the api and mcp is stored the same way and rejects the same bad payload', function () {
    $payload = ['name' => 'Footer', 'content' => '#launch https://example.com'];

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.signatures.store'), $payload)->assertCreated();
    TryPostServer::actingAs($this->user)->tool(CreateSignatureTool::class, $payload)->assertOk();

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->postJson(route('api.signatures.store'), ['content' => 'x'])->assertUnprocessable();
    TryPostServer::actingAs($this->user)->tool(CreateSignatureTool::class, ['content' => 'x'])->assertHasErrors();

    expect(WorkspaceSignature::query()->get()->map->only(['name', 'content'])->all())->toBe([$payload, $payload]);
});

test('the web, api and mcp signature rules refuse the same payloads', function () {
    foreach ([['name' => '', 'content' => 'x'], ['name' => str_repeat('a', 256), 'content' => 'x'], ['name' => 'Ok', 'content' => '']] as $payload) {
        $this->actingAs($this->user)->postJson(route('app.signatures.store'), $payload)->assertUnprocessable();
        auth()->forgetGuards();
        $this->withHeaders(parityApi($this->token))->postJson(route('api.signatures.store'), $payload)->assertUnprocessable();
        TryPostServer::actingAs($this->user)->tool(CreateSignatureTool::class, $payload)->assertHasErrors();
    }

    expect(WorkspaceSignature::query()->count())->toBe(0);
});

test('updating a signature through the api and mcp stores the same name and content', function () {
    $first = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.signatures.update', $first), ['name' => 'New', 'content' => 'Body'])->assertOk();
    TryPostServer::actingAs($this->user)->tool(UpdateSignatureTool::class, ['signature_id' => $second->id, 'name' => 'New', 'content' => 'Body'])->assertOk();

    expect($first->fresh()->only(['name', 'content']))->toBe($second->fresh()->only(['name', 'content']));
});

test('deleting a signature through the api and mcp removes it', function () {
    $first = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
    $second = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.signatures.destroy', $first))->assertNoContent();
    TryPostServer::actingAs($this->user)->tool(DeleteSignatureTool::class, ['signature_id' => $second->id])->assertOk();

    expect(WorkspaceSignature::query()->count())->toBe(0);
});

test('a signature of another workspace is refused with a 404 on the api and an error on mcp', function () {
    $foreign = WorkspaceSignature::factory()->create();

    auth()->forgetGuards();

    $this->withHeaders(parityApi($this->token))->putJson(route('api.signatures.update', $foreign), ['name' => 'X', 'content' => 'Y'])->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->deleteJson(route('api.signatures.destroy', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)->tool(UpdateSignatureTool::class, ['signature_id' => $foreign->id, 'name' => 'X', 'content' => 'Y'])->assertHasErrors();
    TryPostServer::actingAs($this->user)->tool(DeleteSignatureTool::class, ['signature_id' => $foreign->id])->assertHasErrors();

    expect($foreign->fresh())->not->toBeNull();
});

test('listing signatures returns the same resource fields on the api and mcp', function () {
    WorkspaceSignature::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.signatures.index'))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListSignaturesTool::class)->assertOk()->assertStructuredContent(parityMcpPage('signatures', $api));
    expect(array_keys($api->json('data.0')))->toBe(['id', 'name', 'content', 'created_at', 'updated_at']);
});

test('the api and mcp signature lists paginate with the app default like the web list', function () {
    config()->set('app.pagination.default', 2);
    WorkspaceSignature::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->startOfSecond()]);
    $expected = ListSignatures::execute($this->workspace)->pluck('id')->all();

    $first = $this->withHeaders(parityApi($this->token))->getJson(route('api.signatures.index'))->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.total', 3);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route('api.signatures.index', ['page' => 2]))->assertOk()->assertJsonCount(1, 'data');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListSignaturesTool::class)->assertOk()->assertStructuredContent(parityMcpPage('signatures', $first));
    TryPostServer::actingAs($this->user)->tool(ListSignaturesTool::class, ['page' => 2])->assertOk()->assertStructuredContent(parityMcpPage('signatures', $second));

    expect([...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')])->toBe($expected);
});

test('a member who is not an admin manages signatures through mcp but the whole api refuses them', function () {
    $member = workspaceMember($this->workspace, 'member');
    $memberToken = passportToken($member, $this->workspace);

    auth()->forgetGuards();

    $this->withHeaders(parityApi($memberToken))->postJson(route('api.signatures.store'), ['name' => 'Nope', 'content' => 'x'])->assertStatus(Response::HTTP_FORBIDDEN);
    TryPostServer::actingAs($member)->tool(CreateSignatureTool::class, ['name' => 'Yes', 'content' => 'x'])->assertOk();

    expect(WorkspaceSignature::query()->pluck('name')->all())->toBe(['Yes']);
});

test('the api, mcp and web signature lists share one query and order', function () {
    $signatures = WorkspaceSignature::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->startOfSecond()]);
    $expected = ListSignatures::execute($this->workspace)->pluck('id')->all();

    $apiResponse = $this->withHeaders(parityApi($this->token))->getJson(route('api.signatures.index'))->assertOk();
    $api = collect($apiResponse->json('data'))->pluck('id')->all();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(ListSignaturesTool::class)->assertOk()->assertStructuredContent(parityMcpPage('signatures', $apiResponse));

    expect($expected)->toHaveCount(3)->and($api)->toBe($expected)
        ->and($expected)->toBe($signatures->pluck('id')->sortDesc()->values()->all());
});

test('a missing signature name gets the same message on the api and mcp from the shared rules', function () {
    $message = $this->withHeaders(parityApi($this->token))->postJson(route('api.signatures.store'), ['content' => 'x'])->assertUnprocessable()->json('errors.name.0');
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreateSignatureTool::class, ['content' => 'x'])->assertHasErrors([$message]);

    expect(array_keys(SignatureRequestRules::rules()))->toBe(['name', 'content']);
});
