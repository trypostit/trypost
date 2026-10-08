<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\GoogleBusinessPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\YouTubePublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Mail::fake();
    Storage::fake();
    Sleep::fake();

    $this->migration = require database_path('migrations/2026_10_06_112101_mark_post_platforms_scheduled_before_media_checks.php');
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

/**
 * @param  list<array{0: int, 1: int}|'image'>  $files  Videos with their width and height, or a plain image.
 */
function legacyMediaTarget(Workspace $workspace, string $accountState, ContentType $contentType, array $files, PostStatus $status = PostStatus::Scheduled, PlatformStatus $targetStatus = PlatformStatus::Pending): PostPlatform
{
    $account = SocialAccount::factory()->{$accountState}()->create(['workspace_id' => $workspace->id, 'platform_user_id' => 'legacy_account']);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => $status,
        'content' => 'Legacy caption',
    ]);

    $items = collect($files)->map(function (array|string $file, int $index) use ($post): array {
        $row = $file === 'image'
            ? Media::factory()->stored()->ownedByPost($post)->create(['order' => $index])
            : Media::factory()->video()->stored()->ownedByPost($post)->create(['order' => $index, 'meta' => ['width' => $file[0], 'height' => $file[1], 'duration' => 20]]);

        return MediaItem::fromMedia($row)->toArray();
    })->all();

    Post::query()->whereKey($post->id)->update(['media' => json_encode($items)]);

    return PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'status' => $targetStatus,
        'enabled' => true,
    ]);
}

dataset('media main published and 2.0 rejects', [
    'instagram reel 11:1 video' => ['instagram', ContentType::InstagramReel, [[2200, 200]], InstagramPublisher::class],
    'instagram feed 11:1 video' => ['instagram', ContentType::InstagramFeed, [[2200, 200]], InstagramPublisher::class],
    'instagram story 11:1 video' => ['instagram', ContentType::InstagramStory, [[2200, 200]], InstagramPublisher::class],
    'facebook reel 16:9 video' => ['facebook', ContentType::FacebookReel, [[1920, 1080]], FacebookPublisher::class],
    'tiktok video with two videos' => ['tiktok', ContentType::TikTokVideo, [[1080, 1920], [1080, 1920]], TikTokPublisher::class],
    'pinterest pin with two images' => ['pinterest', ContentType::PinterestPin, ['image', 'image'], PinterestPublisher::class],
    'bluesky post with five images' => ['bluesky', ContentType::BlueskyPost, ['image', 'image', 'image', 'image', 'image'], BlueskyPublisher::class],
    'google business post with two images' => ['googleBusiness', ContentType::GoogleBusinessPost, ['image', 'image'], GoogleBusinessPublisher::class],
    'telegram post with eleven images' => ['telegram', ContentType::TelegramPost, array_fill(0, 11, 'image'), TelegramPublisher::class],
]);

test('a video ratio the network accepts publishes without the legacy flag', function (string $accountState, ContentType $contentType, array $files, string $publisherClass) {
    $this->migration->up();
    $target = legacyMediaTarget($this->workspace, $accountState, $contentType, $files);

    $publisher = Mockery::mock($publisherClass);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'relaxed-1', 'url' => 'https://example.com/relaxed-1']);
    $this->app->instance($publisherClass, $publisher);

    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->scheduled_before_media_checks)->toBeFalse()
        ->and($target->fresh()->status)->toBe(PlatformStatus::Published);
})->with([
    'instagram reel 16:9 video' => ['instagram', ContentType::InstagramReel, [[1920, 1080]], InstagramPublisher::class],
    'instagram feed 9:16 video' => ['instagram', ContentType::InstagramFeed, [[1080, 1920]], InstagramPublisher::class],
    'instagram story square video' => ['instagram', ContentType::InstagramStory, [[1080, 1080]], InstagramPublisher::class],
    'youtube short 16:9 video' => ['youtube', ContentType::YouTubeShort, [[1920, 1080]], YouTubePublisher::class],
]);

test('a target scheduled before the deploy reaches the publisher with the media main published', function (string $accountState, ContentType $contentType, array $files, string $publisherClass) {
    $target = legacyMediaTarget($this->workspace, $accountState, $contentType, $files);
    $this->migration->up();

    $publisher = Mockery::mock($publisherClass);
    $publisher->shouldReceive('publish')->once()->andReturn(['id' => 'legacy-1', 'url' => 'https://example.com/legacy-1']);
    $this->app->instance($publisherClass, $publisher);

    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->scheduled_before_media_checks)->toBeTrue()
        ->and($target->fresh()->status)->toBe(PlatformStatus::Published);
})->with('media main published and 2.0 rejects');

test('a target created after the deploy with the same media fails before calling the network', function (string $accountState, ContentType $contentType, array $files, string $publisherClass) {
    $this->migration->up();
    $target = legacyMediaTarget($this->workspace, $accountState, $contentType, $files);

    $publisher = Mockery::mock($publisherClass);
    $publisher->shouldNotReceive('publish');
    $this->app->instance($publisherClass, $publisher);

    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->scheduled_before_media_checks)->toBeFalse()
        ->and($target->fresh()->status)->toBe(PlatformStatus::Failed)
        ->and(data_get($target->fresh()->error_context, 'reason'))->toBe('media_invalid');
})->with('media main published and 2.0 rejects');

test('only targets that publish without user action are marked', function (PostStatus $status, PlatformStatus $targetStatus, bool $marked) {
    $target = legacyMediaTarget($this->workspace, 'instagram', ContentType::InstagramReel, [[1080, 1920]], $status, $targetStatus);

    $this->migration->up();

    expect($target->fresh()->scheduled_before_media_checks)->toBe($marked);
})->with([
    'scheduled' => [PostStatus::Scheduled, PlatformStatus::Pending, true],
    'pending approval' => [PostStatus::PendingApproval, PlatformStatus::Pending, true],
    'publishing' => [PostStatus::Publishing, PlatformStatus::Publishing, true],
    'retrying' => [PostStatus::Publishing, PlatformStatus::Retrying, true],
    'draft' => [PostStatus::Draft, PlatformStatus::Pending, false],
    'failed post' => [PostStatus::Failed, PlatformStatus::Failed, false],
    'failed target of a post still publishing' => [PostStatus::Publishing, PlatformStatus::Failed, false],
    'published' => [PostStatus::Published, PlatformStatus::Published, false],
]);

test('a legacy 16:9 instagram reel publishes through instagram as on main', function () {
    $target = legacyMediaTarget($this->workspace, 'instagram', ContentType::InstagramReel, [[1920, 1080]]);
    $this->migration->up();

    $graph = (string) config('trypost.platforms.instagram.graph_api');
    Http::fake(fn (Request $request) => match (true) {
        str_ends_with($request->url(), '/legacy_account/media') => Http::response(['id' => 'container-1']),
        str_ends_with($request->url(), '/legacy_account/media_publish') => Http::response(['id' => 'ig-media-1']),
        str_contains($request->url(), '/container-1') => Http::response(['status_code' => 'FINISHED']),
        str_contains($request->url(), '/ig-media-1') => Http::response(['permalink' => 'https://www.instagram.com/reel/legacy/']),
        default => Http::response([], 404),
    });

    (new PublishToSocialPlatform($target->fresh()))->handle();

    expect($target->fresh()->status)->toBe(PlatformStatus::Published);
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$graph}/legacy_account/media"
        && data_get($request->data(), 'media_type') === 'REELS'
        && data_get($request->data(), 'video_url') === data_get($target->post->fresh()->media, '0.url'));
});
