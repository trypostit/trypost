<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Notification\Type;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\Status as RepurposeStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Events\PostStatusChanged;
use App\Exceptions\Repurpose\SourceDownloadException;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PublishPost;
use App\Jobs\Repurpose\ProcessRepurposeItem;
use App\Jobs\SendNotification;
use App\Mail\PostApprovalRequested;
use App\Models\Media;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

const REPURPOSE_VIDEO_URL = 'https://93.184.216.34/v.mp4';

function repurposeWithTwoDestinations(): RepurposeItem
{
    Storage::fake();

    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $tiktok = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::TikTok]);
    $youtube = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::YouTube]);

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'destinations' => [
            ['social_account_id' => $tiktok->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value]],
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
            ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
            ->and($post->media)->toHaveCount(1)
            ->and($post->postPlatforms()->enabled()->count())->toBe(1);
    }

    expect(Post::query()->due()->whereIn('id', $posts->pluck('id'))->count())->toBe(2);

    Bus::assertNotDispatched(PublishPost::class);
});

test('the video is downloaded once and every post owns its own copy', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    Http::assertSentCount(1);

    $paths = Post::where('repurpose_item_id', $item->id)->get()
        ->map(fn (Post $post) => data_get($post->media, '0.path'));

    expect($paths->filter())->toHaveCount(2)
        ->and($paths->unique())->toHaveCount(2);
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

    expect($tiktokPlatform->meta)->toEqual(['privacy_level' => PrivacyLevel::PublicToEveryone->value]);
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
        ->and(mb_strlen($captions[Platform::YouTube->value]))
        ->toBeGreaterThan(mb_strlen($captions[Platform::TikTok->value]));
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

test('scheduling the posts announces the status change like any other post', function () {
    Event::fake([PostStatusChanged::class]);
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();

    processItem($item);

    Event::assertDispatchedTimes(PostStatusChanged::class, 2);
});

test('a repurpose set to draft creates the posts and stops there', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $item->repurpose->update(['publish_mode' => PublishMode::Draft]);

    processItem($item->fresh());

    $posts = Post::where('repurpose_item_id', $item->id)->get();

    expect($posts)->toHaveCount(2)
        ->and($item->fresh()->status)->toBe(ItemStatus::Drafted);

    foreach ($posts as $post) {
        expect($post->status)->toBe(PostStatus::Draft)
            ->and($post->scheduled_at)->toBeNull()
            ->and($post->media)->toHaveCount(1);
    }

    Artisan::call('posts:process-scheduled');

    Bus::assertNotDispatched(PublishPost::class);
});

test('a draft run is not repeated when the job runs again', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $item->repurpose->update(['publish_mode' => PublishMode::Draft]);

    processItem($item->fresh());
    $item->update(['status' => ItemStatus::Processing]);
    processItem($item->fresh());

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2)
        ->and($item->fresh()->status)->toBe(ItemStatus::Drafted);
});

test('an item with no usable destination records why', function () {
    Storage::fake();

    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
    $off = SocialAccount::factory()->for(Workspace::factory()->create())->create([
        'platform' => Platform::TikTok,
    ]);

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'destinations' => [
            ['social_account_id' => $off->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value]],
        ],
    ]);

    $item = RepurposeItem::factory()->for($repurpose)->create();

    fakeVideoDownload();

    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, 'caption'))
        ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));

    expect($item->fresh()->status)->toBe(ItemStatus::Failed)
        ->and($item->fresh()->reason)->toBe(ItemReason::NoUsableDestinations);
});

test('an item already in flight still runs after its repurpose is paused', function () {
    $item = repurposeWithTwoDestinations();
    $item->repurpose->update([
        'publish_mode' => PublishMode::Draft,
        'status' => RepurposeStatus::Paused,
        'paused_reason' => PauseReason::SourceUnavailable,
    ]);

    fakeVideoDownload();

    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, 'caption'))
        ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));

    expect($item->fresh()->status)->toBe(ItemStatus::Drafted);
});

test('an exhausted publish-mode item leaves no orphan drafts behind', function () {
    $item = repurposeWithTwoDestinations();

    $post = Post::factory()->create([
        'workspace_id' => $item->repurpose->workspace_id,
        'repurpose_item_id' => $item->id,
        'status' => PostStatus::Draft,
    ]);

    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, 'caption'))
        ->failed(new RuntimeException('gave up'));

    expect(Post::query()->whereKey($post->id)->exists())->toBeFalse()
        ->and($item->fresh()->status)->toBe(ItemStatus::Failed);
});

test('an exhausted draft-mode item keeps its drafts but does not call the run a success', function () {
    $item = repurposeWithTwoDestinations();
    $item->repurpose->update(['publish_mode' => PublishMode::Draft]);

    $post = Post::factory()->create([
        'workspace_id' => $item->repurpose->workspace_id,
        'repurpose_item_id' => $item->id,
        'status' => PostStatus::Draft,
    ]);

    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL, 'caption'))
        ->failed(new RuntimeException('gave up'));

    expect(Post::query()->whereKey($post->id)->exists())->toBeTrue()
        ->and($item->fresh()->status)->toBe(ItemStatus::Failed)
        ->and($item->fresh()->error)->toContain('gave up');
});

test('a draft-mode retry deletes the leftover draft of a run that died halfway, with its media file, and rebuilds the drafts', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $item->repurpose->update(['publish_mode' => PublishMode::Draft]);
    $item->update(['status' => ItemStatus::Processing]);

    $leftover = Post::factory()->create([
        'workspace_id' => $item->repurpose->workspace_id,
        'repurpose_item_id' => $item->id,
        'status' => PostStatus::Draft,
    ]);
    $leftoverMedia = Media::factory()->video()->ownedByPost($leftover)->stored()->create();
    $leftover->update(['media' => [MediaItem::fromMedia($leftoverMedia)->toArray()]]);

    expect(Storage::exists($leftoverMedia->path))->toBeTrue();

    processItem($item->fresh());

    $drafts = Post::where('repurpose_item_id', $item->id)->get();

    expect($drafts)->toHaveCount(2)
        ->and($drafts->pluck('id'))->not->toContain($leftover->id)
        ->and($drafts->every(fn (Post $post): bool => $post->status === PostStatus::Draft))->toBeTrue()
        ->and($item->fresh()->status)->toBe(ItemStatus::Drafted)
        ->and(Post::query()->whereKey($leftover->id)->exists())->toBeFalse()
        ->and(Media::query()->whereKey($leftoverMedia->id)->exists())->toBeFalse()
        ->and(Storage::exists($leftoverMedia->path))->toBeFalse();

    foreach ($drafts as $draft) {
        expect(Storage::exists(data_get($draft->media, '0.path')))->toBeTrue();
    }
});

test('a run that fails while creating the posts leaves no post, row or file behind', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $failCopies = true;
    Media::created(function (Media $media) use (&$failCopies): void {
        if ($failCopies && data_get($media->meta, 'copied_from') !== null) {
            throw new RuntimeException('the worker went away');
        }
    });

    expect(fn () => processItem($item->fresh()))->toThrow(RuntimeException::class);

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(0)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);

    $failCopies = false;
    processItem($item->fresh());

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2)
        ->and($item->fresh()->status)->toBe(ItemStatus::Published);
});

test('three destinations download the video once and end with one row and path per post', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $extra = SocialAccount::factory()->for($item->repurpose->workspace)->create(['platform' => Platform::YouTube]);
    $item->repurpose->update(['destinations' => [
        ...$item->repurpose->destinations,
        ['social_account_id' => $extra->id, 'content_type' => ContentType::YouTubeShort->value, 'meta' => []],
    ]]);

    processItem($item->fresh());

    $posts = Post::where('repurpose_item_id', $item->id)->with('ownedMedia')->get();
    $rows = $posts->flatMap(fn (Post $post) => $post->ownedMedia);
    $moved = $rows->first(fn (Media $media) => data_get($media->meta, 'copied_from') === null);

    Http::assertSentCount(1);
    expect($posts)->toHaveCount(3)
        ->and($posts->every(fn (Post $post) => $post->ownedMedia->count() === 1))->toBeTrue()
        ->and(Media::query()->count())->toBe(3)
        ->and($rows->pluck('path')->unique())->toHaveCount(3)
        ->and($rows->every(fn (Media $media) => Storage::exists($media->path)))->toBeTrue()
        ->and($moved)->not->toBeNull()
        ->and($moved->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($rows->reject(fn (Media $media) => $media->is($moved))->map(fn (Media $media) => data_get($media->meta, 'copied_from'))->unique()->values()->all())
        ->toBe([$moved->id]);
});

test('the stored error never carries the signed source url', function () {
    $item = repurposeWithTwoDestinations();

    $message = 'cURL error 28: Operation timed out for '.REPURPOSE_VIDEO_URL.'?oh=SECRETSIG&oe=68B0';

    (new ProcessRepurposeItem($item, REPURPOSE_VIDEO_URL.'?oh=SECRETSIG&oe=68B0', 'caption'))
        ->failed(new RuntimeException($message));

    expect($item->fresh()->error)->not->toContain('SECRETSIG');
});

test('a redelivered job does not replicate an item that was skipped', function () {
    $item = repurposeWithTwoDestinations();
    fakeVideoDownload();

    $item->update(['status' => ItemStatus::Skipped, 'reason' => ItemReason::PublishedViaTrypost]);

    (new ProcessRepurposeItem($item->fresh(), REPURPOSE_VIDEO_URL, 'caption'))
        ->handle(app(MediaAttacher::class), app(CaptionAdapter::class));

    expect(Post::query()->where('repurpose_item_id', $item->id)->count())->toBe(0)
        ->and($item->fresh()->status)->toBe(ItemStatus::Skipped)
        ->and($item->fresh()->reason)->toBe(ItemReason::PublishedViaTrypost);
});

test('repurpose auto-posts of a requester wait for approval', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();
    $item = repurposeWithTwoDestinations();
    $requester = workspaceMember($item->repurpose->workspace, 'approval');
    $item->repurpose->update(['user_id' => $requester->id]);

    processItem($item->fresh());

    $posts = Post::where('repurpose_item_id', $item->id)->get();

    expect($posts)->toHaveCount(2)
        ->and(Post::query()->due()->whereIn('id', $posts->pluck('id'))->count())->toBe(0);
    $posts->each(fn (Post $post) => expect($post->status)->toBe(PostStatus::PendingApproval)
        ->and($post->approval_requested_at)->not->toBeNull());
    Bus::assertNotDispatched(PublishPost::class);
});

test('a requester repurpose run emails each approver once with every post, never the creator', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, SendNotification::class]);
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();
    $item = repurposeWithTwoDestinations();
    $workspace = $item->repurpose->workspace;
    $publisher = workspaceMember($workspace, 'member');
    $requester = workspaceMember($workspace, 'approval');
    workspaceMember($workspace, 'approval');
    $item->repurpose->update(['user_id' => $requester->id]);
    $ownerId = $workspace->account->owner_id;

    processItem($item->fresh());

    $postIds = Post::where('repurpose_item_id', $item->id)->pluck('id')->sort()->values()->all();
    $notifications = Queue::pushed(SendNotification::class)
        ->filter(fn (SendNotification $job): bool => $job->mailable instanceof PostApprovalRequested);

    expect($ownerId)->not->toBeNull()
        ->and($postIds)->toHaveCount(2)
        ->and($notifications->map(fn (SendNotification $job): string => $job->user->id)->sort()->values()->all())
        ->toBe(collect([$ownerId, $publisher->id])->sort()->values()->all());

    $notifications->each(fn (SendNotification $job) => expect($job->type)->toBe(Type::Collaboration)
        ->and(collect($job->mailable->postIds)->sort()->values()->all())->toBe($postIds)
        ->and($job->mailable->requester->is($requester))->toBeTrue());
    expect(Post::whereIn('id', $postIds)->pluck('approval_requested_by')->unique()->values()->all())->toBe([$requester->id]);
});

test('a requester repurpose in draft mode sends no approval email', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, SendNotification::class]);
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();
    $item = repurposeWithTwoDestinations();
    $workspace = $item->repurpose->workspace;
    workspaceMember($workspace, 'member');
    $requester = workspaceMember($workspace, 'approval');
    $item->repurpose->update(['user_id' => $requester->id, 'publish_mode' => PublishMode::Draft]);

    processItem($item->fresh());

    expect(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
    Queue::assertNotPushed(SendNotification::class);
});

test('a stored google business destination is skipped quietly', function () {
    Bus::fake([PublishPost::class]);
    fakeVideoDownload();

    $item = repurposeWithTwoDestinations();
    $repurpose = $item->repurpose;
    $googleBusiness = SocialAccount::factory()->for($repurpose->workspace)->create(['platform' => Platform::GoogleBusiness]);
    $repurpose->update(['destinations' => [
        ...$repurpose->destinations,
        ['social_account_id' => $googleBusiness->id, 'content_type' => ContentType::GoogleBusinessPost->value, 'meta' => []],
    ]]);

    processItem($item->fresh());

    $platforms = PostPlatform::query()->whereIn('post_id', Post::where('repurpose_item_id', $item->id)->pluck('id'))->pluck('social_account_id');

    expect($item->fresh()->status)->toBe(ItemStatus::Published)
        ->and($platforms)->not->toContain($googleBusiness->id)
        ->and(Post::where('repurpose_item_id', $item->id)->count())->toBe(2);
});
