<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Jobs\Repurpose\ProcessRepurposeItem;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// A public IP literal lets SafeHttpFetcher's SSRF guard pass without a real DNS
// lookup; Http::fake intercepts the request before any network I/O.
const REPURPOSE_VIDEO_URL = 'https://93.184.216.34/v.mp4';

function repurposeWithTwoDestinations(): RepurposeItem
{
    Storage::fake();
    config()->set('trypost.allow_multiple_social_accounts', true);

    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $tiktok = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $youtube = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'destinations' => [
            ['social_account_id' => $tiktok->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => ['privacy_level' => 'PUBLIC_TO_EVERYONE']],
            ['social_account_id' => $youtube->id, 'content_type' => ContentType::YouTubeShort->value, 'meta' => []],
        ],
    ]);

    return RepurposeItem::factory()->for($repurpose)->create();
}

function fakeVideoDownload(): void
{
    Http::fake([
        REPURPOSE_VIDEO_URL => Http::response(
            file_get_contents(base_path('tests/fixtures/sample.mp4')),
            200,
            ['Content-Type' => 'video/mp4'],
        ),
    ]);
}

function processItem(RepurposeItem $item, string $caption = 'My caption'): void
{
    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, $caption))
        ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));
}

test('it creates one post per destination and publishes each', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    $posts = Post::where('repurpose_item_id', $item->id)->get();

    expect($item->fresh()->status)->toBe(ItemStatus::Published)
        ->and($posts)->toHaveCount(2);

    foreach ($posts as $post) {
        expect($post->created_via)->toBe(CreatedVia::Repurpose)
            ->and($post->status)->toBe(PostStatus::Scheduled)
            ->and($post->media)->toHaveCount(1)
            ->and($post->postPlatforms()->enabled()->count())->toBe(1);
    }

    Bus::assertDispatchedTimes(PublishPost::class, 2);
});

test('the video is downloaded once and reused by every post', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    Http::assertSentCount(1);

    $paths = Post::where('repurpose_item_id', $item->id)->get()
        ->map(fn (Post $post) => data_get($post->media, '0.path'));

    expect($paths->filter())->toHaveCount(2)
        ->and($paths->unique())->toHaveCount(1);
});

test('destination meta is carried onto the post platform', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    $tiktokPlatform = PostPlatform::query()
        ->enabled()
        ->whereHas('post', fn ($query) => $query->where('repurpose_item_id', $item->id))
        ->where('platform', Platform::TikTok)
        ->sole();

    expect($tiktokPlatform->meta)->toEqual(['privacy_level' => 'PUBLIC_TO_EVERYONE']);
});

test('a caption over a destination limit is shortened for that post only', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $long = str_repeat('palavra ', 400);

    processItem($item, $long);

    $captions = PostPlatform::query()
        ->enabled()
        ->whereHas('post', fn ($query) => $query->where('repurpose_item_id', $item->id))
        ->with('post')
        ->get()
        ->mapWithKeys(fn (PostPlatform $platform) => [$platform->platform->value => $platform->post->content]);

    expect(Platform::TikTok->contentOverflow($captions[Platform::TikTok->value]))->toBe(0)
        ->and(Platform::YouTube->contentOverflow($captions[Platform::YouTube->value]))->toBe(0)
        ->and(mb_strlen($captions[Platform::TikTok->value]))
        ->toBeGreaterThan(mb_strlen($captions[Platform::YouTube->value]));
});

test('a failed download marks the item failed and leaves no post', function () {
    Bus::fake([PublishPost::class]);
    Http::fake([REPURPOSE_VIDEO_URL => Http::response('', 404)]);

    $item = repurposeWithTwoDestinations();

    processItem($item);

    expect($item->fresh()->status)->toBe(ItemStatus::Failed)
        ->and($item->fresh()->reason)->toBe(ItemReason::DownloadFailed)
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(0);

    Bus::assertNotDispatched(PublishPost::class);
});

test('running the job twice creates no extra posts', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);
    processItem($item->fresh());

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});
