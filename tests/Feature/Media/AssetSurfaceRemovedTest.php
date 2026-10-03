<?php

declare(strict_types=1);

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Mcp\Servers\TryPostServer;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Attributes\Instructions;

test('the asset api routes no longer exist', function (string $name) {
    expect(Route::has($name))->toBeFalse();
})->with(['api.assets.index', 'api.assets.show', 'api.posts.attach-existing-asset']);

test('the MCP server no longer registers asset tools', function () {
    $tools = (new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools'];
    $names = collect($tools)->map(fn (string $tool): string => app($tool)->name())->all();

    expect($names)->not->toContain('list-assets-tool')
        ->not->toContain('get-asset-tool')
        ->not->toContain('attach-existing-asset-tool');
});

test('the MCP instructions describe temporary uploads instead of the asset library', function () {
    $instructions = (new ReflectionClass(TryPostServer::class))->getAttributes(Instructions::class)[0]->newInstance()->value;

    expect($instructions)->not->toContain('Asset Library')
        ->toContain('kept for 24 hours and single-use');
});

test('a save no longer resolves a library row', function () {
    $workspace = Workspace::factory()->create();
    $library = Media::factory()->libraryAsset($workspace)->create();
    $upload = Media::factory()->temporaryUpload($workspace)->create();

    expect(ResolveWorkspaceMedia::execute($workspace, [$library->id, $upload->id])->keys()->all())->toBe([$upload->id]);
});
