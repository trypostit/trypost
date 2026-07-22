<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Services\Media\AssetUsageQuery;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function assetIn(Workspace $workspace): Media
{
    return Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
    ]);
}

function postUsing(Workspace $workspace, Media $media, array $attributes = []): Post
{
    return Post::factory()->create(array_merge([
        'workspace_id' => $workspace->id,
        'media' => [
            ['id' => $media->id, 'path' => $media->path],
            ['id' => $media->id, 'path' => $media->path],
        ],
    ], $attributes));
}

function platformFor(Post $post, array $attributes = []): PostPlatform
{
    return PostPlatform::factory()->create(array_merge([
        'post_id' => $post->id,
        'enabled' => true,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'status' => PostPlatformStatus::Pending,
    ], $attributes));
}

test('usage contract counts content and published rows without inventing draft timestamps', function () {
    $workspace = Workspace::factory()->create();
    $unused = assetIn($workspace);
    $draftOnly = assetIn($workspace);
    $publishedWithoutTimestamp = assetIn($workspace);
    $now = CarbonImmutable::parse('2026-07-22 12:00:00', 'UTC');

    postUsing($workspace, $draftOnly, [
        'status' => PostStatus::Draft,
        'created_at' => '2026-07-01 00:00:00',
        'updated_at' => '2026-07-20 00:00:00',
    ]);

    $post = postUsing($workspace, $publishedWithoutTimestamp, [
        'status' => PostStatus::Published,
        'published_at' => null,
    ]);
    platformFor($post, [
        'status' => PostPlatformStatus::Published,
        'published_at' => null,
    ]);

    $usage = (new AssetUsageQuery)->forAssets($workspace, [
        $unused->id,
        $draftOnly->id,
        $publishedWithoutTimestamp->id,
    ], $now);

    expect($usage[$unused->id]['is_used'])->toBeFalse()
        ->and($usage[$unused->id]['content_usage_count'])->toBe(0)
        ->and($usage[$unused->id]['last_use_contexts'])->toBe([])
        ->and($usage[$draftOnly->id]['is_used'])->toBeTrue()
        ->and($usage[$draftOnly->id]['content_usage_count'])->toBe(1)
        ->and($usage[$draftOnly->id]['usage_count'])->toBe(1)
        ->and($usage[$draftOnly->id]['last_used_at'])->toBeNull()
        ->and($usage[$draftOnly->id]['last_use_basis'])->toBeNull()
        ->and($usage[$draftOnly->id]['last_use_contexts'])->toBe([])
        ->and($usage[$draftOnly->id]['days_since_last_use'])->toBeNull()
        ->and($usage[$publishedWithoutTimestamp->id]['publication_usage_count'])->toBe(1)
        ->and($usage[$publishedWithoutTimestamp->id]['timestamped_publication_usage_count'])->toBe(0)
        ->and($usage[$publishedWithoutTimestamp->id]['published_platforms'])->toBe(['linkedin'])
        ->and($usage[$publishedWithoutTimestamp->id]['published_content_types'])->toBe(['linkedin_post'])
        ->and($usage[$publishedWithoutTimestamp->id]['last_used_at'])->toBeNull();
});

test('configured and published aggregates stay separate', function () {
    $workspace = Workspace::factory()->create();
    $asset = assetIn($workspace);
    $post = postUsing($workspace, $asset, ['status' => PostStatus::PartiallyPublished]);

    platformFor($post, [
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramFeed,
        'status' => PostPlatformStatus::Pending,
    ]);
    platformFor($post, [
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookPost,
        'status' => PostPlatformStatus::Published,
        'published_at' => '2026-07-20 09:00:00',
    ]);

    $usage = (new AssetUsageQuery)->forAssets($workspace, [$asset->id], CarbonImmutable::parse('2026-07-22', 'UTC'));

    expect($usage[$asset->id]['configured_platforms'])->toBe(['facebook', 'instagram'])
        ->and($usage[$asset->id]['configured_content_types'])->toBe(['facebook_post', 'instagram_feed'])
        ->and($usage[$asset->id]['published_platforms'])->toBe(['facebook'])
        ->and($usage[$asset->id]['published_content_types'])->toBe(['facebook_post']);
});

test('last use contexts include every context tied exactly to last used timestamp', function () {
    $workspace = Workspace::factory()->create();
    $asset = assetIn($workspace);
    $timestamp = '2026-07-21 10:30:00';

    $platformPost = postUsing($workspace, $asset, ['status' => PostStatus::Published]);
    platformFor($platformPost, [
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookPost,
        'status' => PostPlatformStatus::Published,
        'published_at' => $timestamp,
    ]);

    $postFallback = postUsing($workspace, $asset, [
        'status' => PostStatus::Published,
        'published_at' => $timestamp,
    ]);
    platformFor($postFallback, [
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'status' => PostPlatformStatus::Published,
        'published_at' => null,
    ]);

    $older = postUsing($workspace, $asset, ['status' => PostStatus::Published]);
    platformFor($older, [
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'status' => PostPlatformStatus::Published,
        'published_at' => '2026-07-01 10:30:00',
    ]);

    $usage = (new AssetUsageQuery)->forAssets($workspace, [$asset->id], CarbonImmutable::parse('2026-07-22 00:00:00', 'UTC'));

    expect($usage[$asset->id]['last_used_at'])->toBe('2026-07-21T10:30:00+00:00')
        ->and($usage[$asset->id]['last_use_basis'])->toBe('mixed')
        ->and($usage[$asset->id]['last_use_contexts'])->toHaveCount(2)
        ->and(collect($usage[$asset->id]['last_use_contexts'])->pluck('used_at')->unique()->values()->all())->toBe(['2026-07-21T10:30:00+00:00'])
        ->and($usage[$asset->id]['latest_content_ids'])->toEqualCanonicalizing([$platformPost->id, $postFallback->id])
        ->and($usage[$asset->id]['latest_content_id'])->toBe(collect([$platformPost->id, $postFallback->id])->sort()->first());
});

test('days since last use uses utc calendar dates instead of complete hours', function () {
    $workspace = Workspace::factory()->create();
    $sameDate = assetIn($workspace);
    $adjacentDate = assetIn($workspace);
    $future = assetIn($workspace);

    $sameDatePost = postUsing($workspace, $sameDate, ['status' => PostStatus::Published]);
    platformFor($sameDatePost, [
        'status' => PostPlatformStatus::Published,
        'published_at' => '2026-07-22 00:30:00',
    ]);

    $adjacentDatePost = postUsing($workspace, $adjacentDate, ['status' => PostStatus::Published]);
    platformFor($adjacentDatePost, [
        'status' => PostPlatformStatus::Published,
        'published_at' => '2026-07-21 23:30:00',
    ]);

    $futurePost = postUsing($workspace, $future, [
        'status' => PostStatus::Scheduled,
        'scheduled_at' => '2026-07-23 00:30:00',
    ]);
    platformFor($futurePost);

    $usage = (new AssetUsageQuery)->forAssets($workspace, [
        $sameDate->id,
        $adjacentDate->id,
        $future->id,
    ], CarbonImmutable::parse('2026-07-22 00:15:00', 'UTC'));

    expect($usage[$sameDate->id]['days_since_last_use'])->toBe(0)
        ->and($usage[$adjacentDate->id]['days_since_last_use'])->toBe(1)
        ->and($usage[$future->id]['days_since_last_use'])->toBe(0);
});

test('usage aggregation is scoped to the requested workspace', function () {
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $asset = assetIn($workspace);
    $otherPost = postUsing($otherWorkspace, $asset, ['status' => PostStatus::Published]);

    platformFor($otherPost, [
        'status' => PostPlatformStatus::Published,
        'published_at' => '2026-07-21 10:00:00',
    ]);

    $usage = (new AssetUsageQuery)->forAssets($workspace, [$asset->id], CarbonImmutable::parse('2026-07-22', 'UTC'));

    expect($usage[$asset->id]['is_used'])->toBeFalse()
        ->and($usage[$asset->id]['publication_usage_count'])->toBe(0)
        ->and($usage[$asset->id]['last_use_contexts'])->toBe([]);
});

test('usage aggregation uses a bounded query set for requested assets', function () {
    $workspace = Workspace::factory()->create();
    $assets = collect(range(1, 5))->map(fn () => assetIn($workspace));

    $assets->each(function (Media $asset) use ($workspace): void {
        $post = postUsing($workspace, $asset, ['status' => PostStatus::Published]);
        platformFor($post, [
            'status' => PostPlatformStatus::Published,
            'published_at' => '2026-07-21 10:00:00',
        ]);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();

    (new AssetUsageQuery)->forAssets($workspace, $assets->pluck('id')->all(), CarbonImmutable::parse('2026-07-22', 'UTC'));

    $queries = collect(DB::getQueryLog())->pluck('query')->join("\n");

    expect($queries)->toContain('media')
        ->and($queries)->toContain('post_platforms');
});

test('postgres asset reference expression casts media json to jsonb containment', function () {
    $query = new AssetUsageQuery;
    $method = new ReflectionMethod($query, 'postgresAssetContainsExpression');
    $method->setAccessible(true);

    expect($method->invoke($query))->toBe('media::jsonb @> ?::jsonb');
});
