<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_04_202400_remove_aspect_ratio_from_post_platforms_meta.php');
});

test('the aspect ratio is dropped from every target and the rest of its meta is kept', function () {
    $cropped = PostPlatform::factory()->create(['meta' => ['aspect_ratio' => '4:5', 'is_ai_generated' => true]]);
    $onlyRatio = PostPlatform::factory()->create(['meta' => ['aspect_ratio' => 'original']]);
    $untouched = PostPlatform::factory()->create(['meta' => ['link_preview' => false]]);
    $empty = PostPlatform::factory()->create(['meta' => null]);

    $this->migration->up();
    $this->migration->up();

    expect($cropped->fresh()->meta)->toEqual(['is_ai_generated' => true])
        ->and($onlyRatio->fresh()->meta)->toEqual([])
        ->and($untouched->fresh()->meta)->toEqual(['link_preview' => false])
        ->and($empty->fresh()->meta)->toBeNull();
});

test('an instagram feed or facebook post target that publishes without user action keeps its crop for the release bake', function () {
    $scheduled = Post::factory()->scheduled()->create();
    $feed = PostPlatform::factory()->instagram()->create(['post_id' => $scheduled->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $facebook = PostPlatform::factory()->create(['post_id' => $scheduled->id, 'platform' => Platform::Facebook, 'content_type' => ContentType::FacebookPost, 'meta' => ['aspect_ratio' => '1:1']]);
    $inFlight = PostPlatform::factory()->instagram()->create(['post_id' => Post::factory()->create(['status' => PostStatus::Publishing])->id, 'status' => PlatformStatus::Retrying, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $pendingApproval = PostPlatform::factory()->instagram()->create(['post_id' => Post::factory()->create(['status' => PostStatus::PendingApproval])->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $published = PostPlatform::factory()->instagram()->published()->create(['post_id' => $scheduled->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $disabled = PostPlatform::factory()->instagram()->disabled()->create(['post_id' => $scheduled->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $story = PostPlatform::factory()->instagram()->create(['post_id' => $scheduled->id, 'content_type' => ContentType::InstagramStory, 'meta' => ['aspect_ratio' => '4:5']]);
    $original = PostPlatform::factory()->instagram()->create(['post_id' => $scheduled->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => 'original']]);
    $draft = PostPlatform::factory()->instagram()->create(['post_id' => Post::factory()->create(['status' => PostStatus::Draft])->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $failed = PostPlatform::factory()->instagram()->failed()->create(['post_id' => Post::factory()->create(['status' => PostStatus::Failed])->id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);
    $failedInFlight = PostPlatform::factory()->instagram()->failed()->create(['post_id' => $inFlight->post_id, 'content_type' => ContentType::InstagramFeed, 'meta' => ['aspect_ratio' => '4:5']]);

    $this->migration->up();

    expect($feed->fresh()->meta)->toEqual(['aspect_ratio' => '4:5'])
        ->and($facebook->fresh()->meta)->toEqual(['aspect_ratio' => '1:1'])
        ->and($inFlight->fresh()->meta)->toEqual(['aspect_ratio' => '4:5'])
        ->and($pendingApproval->fresh()->meta)->toEqual(['aspect_ratio' => '4:5']);

    foreach ([$published, $disabled, $story, $original, $draft, $failed, $failedInFlight] as $target) {
        expect($target->fresh()->meta)->toEqual([]);
    }
});
