<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Repurpose\SourceDownloadException;
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
use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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
        REPURPOSE_VIDEO_URL => fn () => Http::response(
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

    expect(Post::query()->due()->whereIn('id', $posts->pluck('id'))->count())->toBe(2);

    Bus::assertNotDispatched(PublishPost::class);
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

test('a failed download throws so the job retries, leaving no post behind', function () {
    Bus::fake([PublishPost::class]);
    Http::fake([REPURPOSE_VIDEO_URL => Http::response('', 404)]);

    $item = repurposeWithTwoDestinations();

    expect(fn () => processItem($item))->toThrow(SourceDownloadException::class);

    expect($item->fresh()->reason)->toBeNull()
        ->and($item->fresh()->status)->not->toBe(ItemStatus::Failed)
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(0);

    Bus::assertNotDispatched(PublishPost::class);
});

test('a download that never recovers ends as failed once the tries run out', function () {
    Bus::fake([PublishPost::class]);
    Http::fake([REPURPOSE_VIDEO_URL => Http::response('', 404)]);

    $item = repurposeWithTwoDestinations();
    $job = new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, 'My caption');

    try {
        $job->handle(app(MediaAttacher::class), app(CaptionAdapter::class));
    } catch (SourceDownloadException $exception) {
        $job->failed($exception);
    }

    expect($item->fresh()->status)->toBe(ItemStatus::Failed)
        ->and($item->fresh()->reason)->toBe(ItemReason::DownloadFailed)
        ->and($item->fresh()->error)->toContain('Could not download');
});

test('a retried download that succeeds publishes normally', function () {
    Bus::fake([PublishPost::class]);
    Http::fake([
        REPURPOSE_VIDEO_URL => Http::sequence()
            ->push('', 404)
            ->push(file_get_contents(base_path('tests/fixtures/sample.mp4')), 200, ['Content-Type' => 'video/mp4']),
    ]);

    $item = repurposeWithTwoDestinations();

    expect(fn () => processItem($item))->toThrow(SourceDownloadException::class);

    processItem($item);

    expect($item->fresh()->status)->toBe(ItemStatus::Published)
        ->and($item->fresh()->reason)->toBeNull()
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});

test('running the job twice creates no extra posts', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);
    processItem($item->fresh());

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});

test('an interrupted attempt does not leave draft posts behind', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    Post::where('repurpose_item_id', $item->id)->update(['status' => PostStatus::Draft]);
    $item->update(['status' => ItemStatus::Processing]);

    processItem($item->fresh());

    $posts = Post::where('repurpose_item_id', $item->id)->get();

    expect($item->fresh()->status)->toBe(ItemStatus::Published)
        ->and($posts)->toHaveCount(2)
        ->and($posts->every(fn (Post $post) => $post->status === PostStatus::Scheduled))->toBeTrue();
});

test('it still replicates when the repurpose creator is gone', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $item->repurpose->update(['user_id' => null]);

    processItem($item->fresh());

    expect($item->fresh()->status)->toBe(ItemStatus::Published)
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});

test('a retry never destroys posts that are already publishing', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    $ids = Post::where('repurpose_item_id', $item->id)->pluck('id');
    $item->update(['status' => ItemStatus::Processing]);

    processItem($item->fresh());

    expect(Post::whereIn('id', $ids)->count())->toBe(2)
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(2)
        ->and($item->fresh()->status)->toBe(ItemStatus::Published);

    Bus::assertNotDispatched(PublishPost::class);
});

test('a caption survives characters the sanitizer would treat as markup', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item, 'Fiz isso com meu time <3 link na bio');

    $post = Post::where('repurpose_item_id', $item->id)->first();

    expect(app(ContentSanitizer::class)->sanitize($post->content, Platform::TikTok))
        ->toContain('link na bio');
});

test('a destination switched off is skipped instead of publishing nowhere', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $tiktokId = data_get($item->repurpose->destinations, '0.social_account_id');
    SocialAccount::whereKey($tiktokId)->update(['is_active' => false]);

    processItem($item->fresh());

    $posts = Post::where('repurpose_item_id', $item->id)->get();

    expect($posts)->toHaveCount(1)
        ->and($posts->first()->postPlatforms()->enabled()->count())->toBe(1);
});

test('a destination pointing outside the workspace is skipped, never published to', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $repurpose = $item->repurpose;

    $stranger = SocialAccount::factory()
        ->for(Workspace::factory()->create())
        ->create(['platform' => Platform::TikTok]);

    $repurpose->update(['destinations' => [
        ...$repurpose->destinations,
        ['social_account_id' => $stranger->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => []],
    ]]);

    processItem($item);

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});

test('the scheduler claims the repurposed posts, so nothing is dispatched twice', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    Artisan::call('posts:process-scheduled');

    Bus::assertDispatchedTimes(PublishPost::class, 2);

    Artisan::call('posts:process-scheduled');

    Bus::assertDispatchedTimes(PublishPost::class, 2);
});
