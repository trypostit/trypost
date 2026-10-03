<?php

declare(strict_types=1);

use App\Events\PostDeleted;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

function purgeImportedPost(array $attributes = []): array
{
    $post = Post::factory()->imported()->create($attributes);
    $target = PostPlatform::factory()->published()->create(['post_id' => $post->id]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $post->workspace_id, 'post_platform_id' => $target->id]);
    $snapshot = AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');

    return [$post, $publication, $snapshot, $media];
}

test('purging deletes only network posts and their media and keeps analytics', function () {
    Event::fake([PostDeleted::class]);
    [$imported, $publication, $snapshot, $media] = purgeImportedPost();
    $ours = Post::factory()->published()->create();

    $this->artisan('posts:purge-imported')
        ->expectsOutputToContain('1 imported post(s) deleted.')
        ->assertSuccessful();

    expect(Post::query()->whereKey($imported->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($ours->id)->exists())->toBeTrue()
        ->and(Media::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and($publication->fresh())->not->toBeNull()
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and($publication->fresh()->post_dismissed_at)->toBeNull()
        ->and(AnalyticsPublicationDailySnapshot::query()->whereKey($snapshot->id)->exists())->toBeTrue();

    Storage::assertMissing($media->path);
    Event::assertNotDispatched(PostDeleted::class);
});

test('purging can be limited to one workspace', function () {
    [$kept] = purgeImportedPost();
    [$purged] = purgeImportedPost();

    $this->artisan('posts:purge-imported', ['--workspace' => $purged->workspace_id])->assertSuccessful();

    expect(Post::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($purged->id)->exists())->toBeFalse();
});

test('a workspace purge keeps that workspace trypost posts and their media', function () {
    [$imported] = purgeImportedPost();
    $ours = Post::factory()->published()->create(['workspace_id' => $imported->workspace_id]);
    $ourMedia = Media::factory()->ownedByPost($ours)->create();
    Storage::put($ourMedia->path, 'bytes');

    $this->artisan('posts:purge-imported', ['--workspace' => $imported->workspace_id])
        ->expectsOutputToContain('1 imported post(s) deleted.')
        ->assertSuccessful();

    expect(Post::query()->whereKey($imported->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($ours->id)->exists())->toBeTrue()
        ->and(Media::query()->whereKey($ourMedia->id)->exists())->toBeTrue();
    Storage::assertExists($ourMedia->path);
});

test('a workspace that is not a uuid stops the purge with a clear error', function () {
    [$imported] = purgeImportedPost();

    $this->artisan('posts:purge-imported', ['--workspace' => 'not-a-uuid'])
        ->expectsOutputToContain('The --workspace option must be a workspace UUID.')
        ->assertFailed();

    expect(Post::query()->whereKey($imported->id)->exists())->toBeTrue();
});
