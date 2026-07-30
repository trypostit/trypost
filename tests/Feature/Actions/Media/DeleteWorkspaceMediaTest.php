<?php

declare(strict_types=1);

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\Media\DeleteWorkspaceMedia;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('purgeRecords deletes workspace media rows and returns their paths', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $media = $workspace->addMedia(
        UploadedFile::fake()->image('logo.jpg'),
        'logo',
    );
    $path = $media->path;
    Storage::assertExists($path);

    $paths = DeleteWorkspaceMedia::purgeRecords($workspace);

    expect($paths)->toBe([$path]);
    expect(Media::find($media->id))->toBeNull();
    Storage::assertExists($path);

    DeleteOrphanedMediaFiles::execute($paths);

    Storage::assertMissing($path);
});

test('purgeRecords does not delete files still referenced by other media', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $other = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $media = $workspace->addMedia(
        UploadedFile::fake()->image('shared.jpg'),
        'logo',
    );
    $path = $media->path;

    Media::query()->create([
        'mediable_type' => $other->getMorphClass(),
        'mediable_id' => $other->id,
        'collection' => 'logo',
        'type' => $media->type,
        'path' => $path,
        'original_filename' => $media->original_filename,
        'mime_type' => $media->mime_type,
        'size' => $media->size,
        'order' => 0,
        'meta' => [],
    ]);

    $paths = DeleteWorkspaceMedia::purgeRecords($workspace);

    DeleteOrphanedMediaFiles::execute($paths);

    expect(Media::query()->where('path', $path)->count())->toBe(1);
    Storage::assertExists($path);
});
