<?php

declare(strict_types=1);

use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('purge workspace deletes the workspace and returns media paths', function () {
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

    $paths = PurgeWorkspace::execute($workspace);

    expect($paths)->toBe([$path]);
    expect(Workspace::find($workspace->id))->toBeNull();
    expect(Media::find($media->id))->toBeNull();
    Storage::assertExists($path);
});
