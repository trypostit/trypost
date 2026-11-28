<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
});

test('showing the workspace returns the same fields on the api and mcp, without logo or members', function () {
    $api = $this->withHeaders(parityApi($this->token))->getJson(route('api.workspace.show'))->assertOk()->json();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(GetWorkspaceTool::class)->assertOk()->assertStructuredContent($api);

    expect(array_keys($api))->toBe(['id', 'name', 'created_at', 'updated_at', 'me'])
        ->and($api['id'])->toBe($this->workspace->id)
        ->and($api['name'])->toBe($this->workspace->name);
});

test('a member who is not an admin reads the workspace through mcp but the whole api refuses them', function () {
    $member = workspaceMember($this->workspace, 'member');
    $token = passportToken($member, $this->workspace);

    $this->withHeaders(parityApi($token))->getJson(route('api.workspace.show'))->assertForbidden();
    auth()->forgetGuards();

    TryPostServer::actingAs($member)->tool(GetWorkspaceTool::class)->assertOk()->assertStructuredContent(
        fn ($json) => $json->where('id', $this->workspace->id)->etc()
    );
});

test('the web settings page refuses a member who is not an admin', function () {
    $member = workspaceMember($this->workspace, 'member');

    $this->actingAs($member)->get(route('app.workspace.settings'))->assertForbidden();
});

test('workspace settings, members and invitations have no api route', function () {
    $apiRoutes = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $name): bool => str_starts_with($name, 'api.workspace') || str_starts_with($name, 'api.members') || str_starts_with($name, 'api.invites'))
        ->values()
        ->all();

    expect($apiRoutes)->toEqual(['api.workspace.show']);

    foreach (['app.workspace.settings.update', 'app.workspace.upload-logo', 'app.workspaces.store', 'app.workspaces.destroy', 'app.workspaces.switch', 'app.members', 'app.members.update', 'app.members.remove', 'app.invites.store', 'app.invites.destroy', 'app.invites.accept', 'app.invites.decline'] as $name) {
        expect(Route::has($name))->toBeTrue();
    }
});

test('the web updates the workspace name while the api rejects the write', function () {
    $this->actingAs($this->user)->put(route('app.workspace.settings.update'), ['name' => 'Renamed'])->assertRedirect();
    expect($this->workspace->fresh()->name)->toBe('Renamed');

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->putJson(route('api.workspace.show'), ['name' => 'Again'])->assertStatus(Response::HTTP_METHOD_NOT_ALLOWED);
    expect($this->workspace->fresh()->name)->toBe('Renamed');
});
