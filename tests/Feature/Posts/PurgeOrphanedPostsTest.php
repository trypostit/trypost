<?php

declare(strict_types=1);

use App\Enums\Post\Origin;
use App\Events\PostDeleted;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

/**
 * A post left behind by a disconnect from before posts were deleted with
 * their channel: its target lost the account through the nullOnDelete FK.
 *
 * @return array{0: Post, 1: PostPlatform, 2: Media}
 */
function orphanedPost(array $attributes = []): array
{
    $post = Post::factory()->published()->create($attributes);
    $target = PostPlatform::factory()->published()->create(['post_id' => $post->id]);
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');
    SocialAccount::query()->whereKey($target->social_account_id)->delete();

    return [$post, $target->fresh(), $media];
}

test('purging deletes posts left without a channel and their media, and is idempotent', function () {
    Event::fake([PostDeleted::class]);
    [$orphan, $target, $media] = orphanedPost();
    [$importedOrphan] = orphanedPost(['origin' => Origin::Network, 'user_id' => null]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $orphan->workspace_id, 'post_platform_id' => $target->id]);
    $live = PostPlatform::factory()->published()->create();

    expect($target->social_account_id)->toBeNull();

    $this->artisan('posts:purge-orphaned')
        ->expectsOutputToContain('2 orphaned post(s) deleted, 0 orphaned target(s) removed from posts on other channels.')
        ->assertSuccessful();

    expect(Post::query()->whereKey([$orphan->id, $importedOrphan->id])->exists())->toBeFalse()
        ->and(Media::query()->whereKey($media->id)->exists())->toBeFalse()
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and($publication->fresh()->post_dismissed_at)->toBeNull()
        ->and(PostPlatform::query()->whereKey($live->id)->exists())->toBeTrue();
    Storage::assertMissing($media->path);
    Event::assertNotDispatched(PostDeleted::class);

    $this->artisan('posts:purge-orphaned')
        ->expectsOutputToContain('0 orphaned post(s) deleted, 0 orphaned target(s) removed from posts on other channels.')
        ->assertSuccessful();
});

test('a legacy post keeps its live channels and only loses the orphaned target', function () {
    [$post, $orphanTarget] = orphanedPost();
    $live = PostPlatform::factory()->published()->create(['post_id' => $post->id]);

    $this->artisan('posts:purge-orphaned')
        ->expectsOutputToContain('0 orphaned post(s) deleted, 1 orphaned target(s) removed from posts on other channels.')
        ->assertSuccessful();

    expect($post->fresh())->not->toBeNull()
        ->and($post->postPlatforms()->pluck('id')->all())->toBe([$live->id])
        ->and(PostPlatform::query()->whereKey($orphanTarget->id)->exists())->toBeFalse();
});

test('purging can be limited to one workspace', function () {
    [$kept] = orphanedPost();
    [$purged] = orphanedPost();

    $this->artisan('posts:purge-orphaned', ['--workspace' => $purged->workspace_id])->assertSuccessful();

    expect(Post::query()->whereKey($kept->id)->exists())->toBeTrue()
        ->and(Post::query()->whereKey($purged->id)->exists())->toBeFalse();
});

test('the workspace option must be a uuid', function () {
    $this->artisan('posts:purge-orphaned', ['--workspace' => 'nope'])->assertFailed();
});
