<?php

declare(strict_types=1);

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

    $this->migration = require database_path('migrations/2026_10_02_205527_fold_legacy_workspace_media_into_library.php');
    $this->workspace = Workspace::factory()->create();
});

/**
 * A workspace row as an older release stored it, written past the model's
 * ownership guard.
 *
 * @param  array<string, mixed>  $legacy
 */
function legacyWorkspaceMedia(Workspace $workspace, array $legacy): Media
{
    $media = Media::factory()->libraryAsset($workspace)->create(['path' => 'medias/'.Str::uuid().'.webp']);
    Storage::put($media->path, 'ai bytes');
    DB::table('medias')->where('id', $media->id)->update($legacy);

    return $media;
}

test('ai images join the library with a workspace owner, including rows stored with the class name', function () {
    $classNamed = legacyWorkspaceMedia($this->workspace, ['collection' => 'ai-generated', 'mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null]);
    $aliased = legacyWorkspaceMedia($this->workspace, ['collection' => 'ai-generated']);
    $logo = legacyWorkspaceMedia($this->workspace, ['collection' => 'logo']);
    $classNamedLogo = legacyWorkspaceMedia($this->workspace, ['collection' => 'logo', 'mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null]);
    $classNamedAsset = legacyWorkspaceMedia($this->workspace, ['mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null]);
    $deadWorkspace = (string) Str::uuid();
    $gone = legacyWorkspaceMedia($this->workspace, ['collection' => 'ai-generated', 'mediable_type' => 'App\\Models\\Workspace', 'mediable_id' => $deadWorkspace, 'workspace_id' => null]);

    $this->migration->up();
    $this->migration->up();

    $row = fn (Media $media): array => (array) DB::table('medias')->where('id', $media->id)->first(['collection', 'mediable_type', 'workspace_id']);

    expect($row($classNamed))->toEqual(['collection' => Media::LIBRARY_COLLECTION, 'mediable_type' => 'workspace', 'workspace_id' => $this->workspace->id])
        ->and($row($aliased))->toEqual(['collection' => Media::LIBRARY_COLLECTION, 'mediable_type' => 'workspace', 'workspace_id' => $this->workspace->id])
        ->and($row($logo))->toEqual(['collection' => 'logo', 'mediable_type' => 'workspace', 'workspace_id' => $this->workspace->id])
        ->and($row($classNamedLogo))->toEqual(['collection' => 'logo', 'mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null])
        ->and($row($classNamedAsset))->toEqual(['collection' => Media::LIBRARY_COLLECTION, 'mediable_type' => 'workspace', 'workspace_id' => $this->workspace->id])
        ->and($row($gone))->toEqual(['collection' => 'ai-generated', 'mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null]);
});

test('once folded, the adoption copies a referenced ai image onto its post and deletes the unreferenced one', function () {
    $used = legacyWorkspaceMedia($this->workspace, ['collection' => 'ai-generated', 'mediable_type' => 'App\\Models\\Workspace', 'workspace_id' => null]);
    $unused = legacyWorkspaceMedia($this->workspace, ['collection' => 'ai-generated']);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'media' => [['id' => $used->id, 'path' => $used->path, 'url' => $used->url, 'type' => 'image', 'mime_type' => 'image/webp']],
    ]);

    $this->migration->up();
    $result = AdoptWorkspaceLibrary::execute($this->workspace);

    $copy = Media::query()->findOrFail(data_get($post->fresh()->media, '0.id'));

    expect($result)->toMatchArray(['copied' => 1, 'deleted' => 2, 'skipped' => 0, 'deferred' => 0])
        ->and($copy->post_id)->toBe($post->id)
        ->and(Storage::get($copy->path))->toBe('ai bytes')
        ->and(Media::query()->whereKey([$used->id, $unused->id])->exists())->toBeFalse();
});
