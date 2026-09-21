<?php

declare(strict_types=1);

use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
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

test('purge workspace prunes google business jpegs before the cascade', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]);
    $target = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
    Storage::put($path, 'image');

    PurgeWorkspace::execute($workspace);

    expect(Workspace::find($workspace->id))->toBeNull();
    Storage::assertMissing($path);
});
