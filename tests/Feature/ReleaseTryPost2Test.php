<?php

declare(strict_types=1);

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Console\Commands\Scripts\ReleaseTryPost2;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Jobs\Media\AdoptWorkspaceLibraryJob;
use App\Jobs\Media\DeleteMediaFiles;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    Storage::fake();
    releaseFakeQueue();

    $this->workspace = Workspace::factory()->create();
});

/**
 * Fakes the jobs the release queues; called again after the fixtures so the
 * jobs their observers queue are not counted.
 */
function releaseFakeQueue(): void
{
    Queue::fake([BackfillTryPostPublications::class, BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, DiscoverAccountPublications::class]);
}

function releaseLibraryAsset(Workspace $workspace): Media
{
    $asset = Media::factory()->libraryAsset($workspace)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($asset->path, 'library bytes');

    return $asset;
}

/**
 * @return array<string, mixed>
 */
function releaseLibraryItem(Media $asset): array
{
    return ['id' => $asset->id, 'path' => $asset->path, 'url' => $asset->url, 'type' => 'image', 'mime_type' => 'image/jpeg'];
}

/**
 * A legacy scheduled post on two Instagram channels that uses a library file.
 */
function releaseLegacyPost(Workspace $workspace, Media $asset): Post
{
    $post = Post::factory()->scheduled()->create(['workspace_id' => $workspace->id, 'media' => [releaseLibraryItem($asset)]]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);

    foreach ($accounts as $account) {
        PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => $account->platform]);
    }

    return $post;
}

function releaseOrphanedPost(Workspace $workspace): Post
{
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id]);
    $target = PostPlatform::factory()->published()->create(['post_id' => $post->id]);
    SocialAccount::query()->whereKey($target->social_account_id)->delete();

    return $post;
}

/**
 * The step outputs come from the commands the release calls, so their
 * positions in the output give the order the steps ran in.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function runRelease(array $options = []): array
{
    $output = new BufferedOutput;
    $code = Artisan::call('release:trypost-2', ['--force' => true, ...$options], $output);

    return [$code, $output->fetch()];
}

/**
 * @param  list<string>  $needles
 */
function expectInOrder(string $output, array $needles): void
{
    $positions = array_map(fn (string $needle): int|false => strpos($output, $needle), $needles);

    expect($positions)->not->toContain(false);

    $sorted = $positions;
    sort($sorted);

    expect($positions)->toBe($sorted);
}

test('pending migrations abort the release before any step runs', function () {
    $directory = storage_path('framework/testing/release-migrations');
    File::ensureDirectoryExists($directory);
    File::put("{$directory}/2099_01_01_000000_release_probe.php", '<?php return new class extends Illuminate\Database\Migrations\Migration { public function up(): void {} };');
    app(Migrator::class)->path($directory);
    $asset = releaseLibraryAsset($this->workspace);
    $post = releaseLegacyPost($this->workspace, $asset);

    try {
        [$code, $output] = runRelease();
    } finally {
        File::deleteDirectory($directory);
    }

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain('1 pending migration(s)')
        ->and($output)->toContain('2099_01_01_000000_release_probe')
        ->and($output)->not->toContain('[1/7]')
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and($post->postPlatforms()->count())->toBe(2);
});

test('a dry run prints every step and changes nothing', function () {
    $asset = releaseLibraryAsset($this->workspace);
    $post = releaseLegacyPost($this->workspace, $asset);
    $orphan = releaseOrphanedPost($this->workspace);

    releaseFakeQueue();
    $files = Storage::allFiles();

    [$code, $output] = runRelease(['--dry-run' => true]);

    expect($code)->toBe(Command::SUCCESS)
        ->and(Storage::allFiles())->toBe($files)
        ->and($output)->toMatch('/\| Media library adoption\s+\| dry run\s+\|/')
        ->and($output)->toMatch('/\| Media audit \(read-only\)\s+\| dry run\s+\|/');
    expectInOrder($output, [
        'Would run: php artisan posts:purge-orphaned',
        'would delete 1 post(s) and detach 0 target(s)',
        'Would run: php artisan posts:split-legacy-active',
        'Editable with multiple enabled targets',
        'References to copy',
        'Would run: php artisan analytics:backfill-existing --chunk=100 --delay=0',
        'Would run: php artisan analytics:dispatch-publication-discovery --platform=instagram --platform=instagram-facebook',
        '| json_drift',
        'Dry run: nothing was changed.',
    ]);

    expect(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Post::query()->count())->toBe(2)
        ->and($post->postPlatforms()->count())->toBe(2)
        ->and(Post::query()->whereKey($orphan->id)->exists())->toBeTrue();
    Queue::assertNothingPushed();
});

test('a full run executes the steps in order, waits for the queued adoption and is a no-op the second time', function () {
    $asset = releaseLibraryAsset($this->workspace);
    releaseLegacyPost($this->workspace, $asset);
    $orphan = releaseOrphanedPost($this->workspace);

    releaseFakeQueue();

    [$code, $output] = runRelease(['--include-unsubscribed' => true, '--backfill-chunk' => 10, '--backfill-delay' => 60]);

    expect($code)->toBe(Command::SUCCESS);
    expectInOrder($output, [
        '1 orphaned post(s) deleted',
        'Split 1 original posts and created 1 independent posts.',
        '1/1 workspace(s) done, 0 library row(s) left, 0 stopped',
        'accounts_dispatched=2',
        '[6/7] Instagram stories discovery',
        '| orphaned_files',
        '0 unexpected',
        'Release steps done.',
    ]);

    expect(Media::query()->whereKey($asset->id)->exists())->toBeFalse()
        ->and(Post::query()->whereKey($orphan->id)->exists())->toBeFalse();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->get();

    expect($posts)->toHaveCount(2);

    foreach ($posts as $owner) {
        $copy = Media::query()->findOrFail(data_get($owner->media, '0.id'));

        expect($copy->post_id)->toBe($owner->id)
            ->and($owner->postPlatforms()->enabled()->count())->toBe(1);
    }

    Queue::assertPushed(BootstrapAccountAnalytics::class, 2);

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::SUCCESS);
    expectInOrder($output, [
        '0 orphaned post(s) deleted',
        'Split 0 original posts and created 0 independent posts.',
        'No library media to adopt.',
        'Skipped: already dispatched at',
        '0 expected, 0 unexpected',
    ]);

    expect(Post::query()->where('workspace_id', $this->workspace->id)->count())->toBe(2)
        ->and(Media::query()->where('workspace_id', $this->workspace->id)->count())->toBe(2);
    Queue::assertPushed(BootstrapAccountAnalytics::class, 2);

    runRelease(['--include-unsubscribed' => true, '--rerun-backfill' => true]);

    Queue::assertPushed(BootstrapAccountAnalytics::class, 4);
});

test('the adoption dispatches one job per workspace on its own queue and waits until every library is adopted', function () {
    $other = Workspace::factory()->create();
    $assets = [releaseLibraryAsset($this->workspace), releaseLibraryAsset($other)];
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [releaseLibraryItem($assets[0])]]);
    Post::factory()->published()->create(['workspace_id' => $other->id, 'media' => [releaseLibraryItem($assets[1])]]);
    releaseFakeQueue();
    Queue::fake([AdoptWorkspaceLibraryJob::class, BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class, DiscoverAccountPublications::class, BackfillTryPostPublications::class]);
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(function (): void {
        Queue::pushed(AdoptWorkspaceLibraryJob::class)->each(function (AdoptWorkspaceLibraryJob $job): void {
            $job->handle();
            (new UniqueLock(Cache::store()))->release($job);
        });
    });

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::SUCCESS, $output)
        ->and($output)->toContain('0/2 workspace(s) done, 2 library row(s) left, 0 stopped')
        ->and($output)->toContain('2/2 workspace(s) done, 0 library row(s) left, 0 stopped')
        ->and($output)->toContain('2 of 2 workspace(s) adopted')
        ->and(Media::query()->whereKey([$assets[0]->id, $assets[1]->id])->exists())->toBeFalse();
    Queue::assertPushed(AdoptWorkspaceLibraryJob::class, 2);
    Queue::assertPushedOn(AdoptWorkspaceLibraryJob::QUEUE, AdoptWorkspaceLibraryJob::class);
    Sleep::assertSleptTimes(1);
});

test('a workspace still adopting when the timeout passes is left to its job and the release goes on', function () {
    Sleep::fake(syncWithCarbon: true);
    $asset = releaseLibraryAsset($this->workspace);
    Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => Status::Publishing, 'media' => [releaseLibraryItem($asset)]]);

    [$code, $output] = runRelease(['--adoption-timeout' => 60]);

    expect($code)->toBe(Command::SUCCESS, $output)
        ->and($output)->toContain('1 workspace(s) still adopting after 60s')
        ->and($output)->toContain('[6/7] Instagram stories discovery')
        ->and($output)->toContain('| json_drift         | 1        | 0          |')
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();
    Sleep::assertSleptTimes(4);
});

test('a workspace whose job stops without adopting is reported as failed and the release goes on', function () {
    $healthyAsset = releaseLibraryAsset($this->workspace);
    $healthyPost = Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [releaseLibraryItem($healthyAsset)]]);
    $broken = Workspace::factory()->create();
    $brokenAsset = releaseLibraryAsset($broken);
    Storage::delete($brokenAsset->path);
    Post::factory()->published()->create(['workspace_id' => $broken->id, 'media' => [releaseLibraryItem($brokenAsset)]]);

    releaseFakeQueue();

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain("1 workspace(s) stopped with library media left: {$broken->id}")
        ->and($output)->toContain('[6/7] Instagram stories discovery')
        ->and($output)->toContain('| Media audit (read-only)')
        ->and($output)->toContain('1 failed step(s)')
        ->and(Media::query()->whereKey($healthyAsset->id)->exists())->toBeFalse()
        ->and(Media::query()->findOrFail(data_get($healthyPost->fresh()->media, '0.id'))->post_id)->toBe($healthyPost->id)
        ->and(Media::query()->whereKey($brokenAsset->id)->exists())->toBeTrue();
});

test('the final audit counts what the release leaves behind on purpose as expected and passes', function () {
    Queue::fake([DeleteMediaFiles::class]);
    $asset = releaseLibraryAsset($this->workspace);
    $other = Workspace::factory()->create();
    Post::factory()->published()->create(['workspace_id' => $other->id, 'media' => [releaseLibraryItem($asset)]]);
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [['id' => (string) Str::uuid(), 'path' => 'medias/gone.jpg', 'url' => 'https://cdn.example/gone.jpg', 'type' => 'image']]]);
    $unused = releaseLibraryAsset($this->workspace);
    Storage::put('medias/stray-before-the-release.jpg', 'stray');

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::SUCCESS, $output)
        ->and($output)->toContain('| orphaned_files     | 2        | 0          |')
        ->and($output)->toContain('| json_drift         | 2        | 0          |')
        ->and($output)->toContain('0 unexpected')
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue()
        ->and(Media::query()->whereKey($unused->id)->exists())->toBeFalse();
    Storage::assertExists($unused->path);
});

test('an audit finding the release did not expect fails it and is listed', function () {
    $asset = releaseLibraryAsset($this->workspace);
    $post = Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [releaseLibraryItem($asset)]]);
    Media::created(function (Media $media): void {
        Storage::delete($media->path);
    });

    [$code, $output] = runRelease();

    $copy = Media::query()->findOrFail(data_get($post->fresh()->media, '0.id'));

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain("unexpected missing_files: media_id={$copy->id}")
        ->and($output)->toContain('1 unexpected audit finding(s)');
});

test('a referenced media row the release deletes by mistake is an unexpected finding', function () {
    $asset = releaseLibraryAsset($this->workspace);
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [releaseLibraryItem($asset)]]);
    $kept = Post::factory()->published()->create(['workspace_id' => $this->workspace->id]);
    $victim = Media::factory()->ownedByPost($kept)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($victim->path, 'victim bytes');
    $kept->forceFill(['media' => [releaseLibraryItem($victim)]])->saveQuietly();
    Media::created(function () use ($victim): void {
        DB::table('medias')->where('id', $victim->id)->delete();
    });

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain("media_id={$victim->id} problem=json_without_row")
        ->and($output)->toContain('unexpected audit finding(s)');
});

test('the backfill marker lives in the database cache, so a flushed default cache does not dispatch it again', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    releaseFakeQueue();

    runRelease(['--include-unsubscribed' => true]);
    Cache::flush();
    [, $output] = runRelease(['--include-unsubscribed' => true]);

    expect(Cache::store('database')->get(ReleaseTryPost2::BACKFILL_DISPATCHED_KEY))->not->toBeNull()
        ->and($output)->toContain('Skipped: already dispatched at');
    Queue::assertPushed(BootstrapAccountAnalytics::class, 1);
});

test('a failure while queueing the adoption is reported and the release goes on', function () {
    Sleep::fake();
    $asset = releaseLibraryAsset($this->workspace);
    Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'media' => [releaseLibraryItem($asset)]]);
    Artisan::command('media:adopt-library {--force} {--dry-run}', function (): void {
        throw new RuntimeException('queue is down');
    });

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::FAILURE)
        ->and($output)->toContain('Could not queue AdoptWorkspaceLibraryJob: queue is down')
        ->and($output)->toContain('[7/7] Media audit')
        ->and($output)->toContain('| Media library adoption')
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();
    Sleep::assertNeverSlept();
});

test('a partially published post with a target on a deleted channel ends as one published and one failed post', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'status' => Status::PartiallyPublished, 'content' => 'Launch video']);
    $youtube = PostPlatform::factory()->youtube()->published()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id]);
    $instagram = PostPlatform::factory()->instagram()->failed()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id]);
    $tiktok = PostPlatform::factory()->tiktok()->failed()->create(['post_id' => $post->id, 'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $this->workspace->id])->id]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $this->workspace->id, 'post_platform_id' => $youtube->id]);
    $tiktok->socialAccount->delete();

    releaseFakeQueue();

    [$code, $output] = runRelease();

    $posts = Post::query()->where('workspace_id', $this->workspace->id)->with('postPlatforms')->get()->keyBy(fn (Post $split): string => $split->postPlatforms->sole()->id);

    expect($code)->toBe(Command::SUCCESS, $output)
        ->and($posts->keys()->sort()->values()->all())->toBe(collect([$youtube->id, $instagram->id])->sort()->values()->all())
        ->and($posts[$youtube->id]->status)->toBe(Status::Published)
        ->and($posts[$instagram->id]->status)->toBe(Status::Failed)
        ->and($posts[$youtube->id]->post_group_id)->toBe($posts[$instagram->id]->post_group_id)
        ->and($posts->pluck('content')->unique()->all())->toBe(['Launch video'])
        ->and(PostPlatform::query()->whereKey($tiktok->id)->exists())->toBeFalse()
        ->and($publication->fresh()->post_platform_id)->toBe($youtube->id)
        ->and($publication->fresh()->postPlatform->post_id)->toBe($posts[$youtube->id]->id);
});

/**
 * Everything a second run could change, in a comparable shape.
 *
 * @return array<string, mixed>
 */
function releaseFingerprint(): array
{
    return [
        'posts' => Post::query()->orderBy('id')->get()->map(fn (Post $post): array => [
            $post->id, $post->workspace_id, $post->post_group_id, $post->content, $post->media, $post->status->value, $post->scheduled_at?->toIso8601String(),
        ])->all(),
        'targets' => PostPlatform::query()->orderBy('id')->get()->map(fn (PostPlatform $target): array => [
            $target->id, $target->post_id, $target->social_account_id, $target->enabled, $target->status->value, $target->meta,
        ])->all(),
        'media' => Media::query()->orderBy('id')->get()->map(fn (Media $media): array => [
            $media->id, $media->collection, $media->workspace_id, $media->post_id, $media->path,
        ])->all(),
        'labels' => DB::table('post_workspace_label')->orderBy('post_id')->orderBy('workspace_label_id')->get(['post_id', 'workspace_label_id'])->map(fn (object $row): array => (array) $row)->all(),
        'notes' => PostNote::query()->orderBy('id')->get(['id', 'post_id', 'body'])->toArray(),
        'files' => collect(Storage::allFiles())->sort()->values()->all(),
    ];
}

test('a realistic pre-release workspace keeps every post, target, label, note and file it should after the release, and a second run changes nothing', function (bool $adoptedOnBoot) {
    $workspace = $this->workspace;
    $workspace->owner->forceFill(['timezone' => 'America/Sao_Paulo'])->saveQuietly();
    $accounts = SocialAccount::factory()->count(3)->create(['workspace_id' => $workspace->id]);
    $deadAccount = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);
    [$launch, $promo] = WorkspaceLabel::factory()->count(2)->create(['workspace_id' => $workspace->id])->all();
    $scheduledAt = now()->addDays(3)->startOfSecond();

    $shared = releaseLibraryAsset($workspace);
    $draftOnly = releaseLibraryAsset($workspace);
    $orphanOnly = releaseLibraryAsset($workspace);
    $unused = releaseLibraryAsset($workspace);
    $libraryPaths = [$shared->path, $draftOnly->path, $orphanOnly->path, $unused->path];

    $target = fn (Post $post, SocialAccount $account, array $attributes = []): PostPlatform => PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'meta' => ['first_comment' => "comment for {$account->id}"],
        ...$attributes,
    ]);

    $draft = Post::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'content' => 'Draft caption',
        'media' => [
            [...releaseLibraryItem($shared), 'meta' => ['alt_text' => 'Shared alt']],
            [...releaseLibraryItem($draftOnly), 'meta' => ['alt_text' => 'Draft alt']],
        ],
    ]);
    $target($draft, $accounts[0]);
    $target($draft, $accounts[1], ['content_type' => ContentType::LinkedInPost]);
    $draft->labels()->attach($launch);

    $scheduled = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'content' => 'Scheduled caption',
        'scheduled_at' => $scheduledAt,
        'media' => [releaseLibraryItem($shared)],
    ]);
    $target($scheduled, $accounts[0]);
    $target($scheduled, $accounts[1]);
    $disabled = PostPlatform::factory()->disabled()->create(['post_id' => $scheduled->id, 'social_account_id' => $accounts[2]->id]);
    $scheduled->labels()->attach([$launch->id, $promo->id]);
    $note = PostNote::factory()->create(['post_id' => $scheduled->id, 'user_id' => $scheduled->user_id]);

    $published = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'content' => 'Published caption',
        'media' => [releaseLibraryItem($shared)],
    ]);
    PostPlatform::factory()->published()->create(['post_id' => $published->id, 'social_account_id' => $accounts[0]->id]);
    PostPlatform::factory()->failed()->create(['post_id' => $published->id, 'social_account_id' => $accounts[1]->id]);

    $orphan = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'media' => [releaseLibraryItem($orphanOnly)]]);
    PostPlatform::factory()->published()->create(['post_id' => $orphan->id, 'social_account_id' => $deadAccount->id]);

    $mixed = Post::factory()->scheduled()->create(['workspace_id' => $workspace->id, 'content' => 'Mixed caption', 'scheduled_at' => $scheduledAt]);
    $deadTarget = $target($mixed, $deadAccount);
    $liveTarget = $target($mixed, $accounts[2]);

    $other = Workspace::factory()->create();
    $otherAccount = SocialAccount::factory()->create(['workspace_id' => $other->id]);
    $otherPost = Post::factory()->scheduled()->create(['workspace_id' => $other->id, 'content' => 'Untouched caption']);
    $otherMedia = Media::factory()->ownedByPost($otherPost)->create(['path' => 'medias/'.Str::uuid().'.jpg']);
    Storage::put($otherMedia->path, 'owned bytes');
    $otherPost->forceFill(['media' => [releaseLibraryItem($otherMedia)]])->saveQuietly();
    PostPlatform::factory()->create(['post_id' => $otherPost->id, 'social_account_id' => $otherAccount->id]);

    $deadAccount->delete();

    $originals = collect([$draft, $scheduled, $published, $mixed, $otherPost])->mapWithKeys(fn (Post $post): array => [$post->id => [
        'content' => $post->content,
        'status' => $post->status,
        'scheduled_at' => $post->scheduled_at?->toIso8601String(),
        'labels' => $post->labels()->pluck('workspace_labels.id')->sort()->values()->all(),
        'items' => collect($post->fresh()->media)->map(fn (array $item): array => [
            'fields' => Arr::except($item, ['id', 'path', 'url']),
            'bytes' => Storage::get($item['path']),
        ])->all(),
    ]]);
    $targets = PostPlatform::query()->whereNotNull('social_account_id')->get()->keyBy('id')
        ->map(fn (PostPlatform $row): array => [$row->post_id, $row->social_account_id, $row->enabled, $row->status, $row->content_type, $row->meta]);

    if ($adoptedOnBoot) {
        AdoptWorkspaceLibrary::execute($workspace);
    }

    releaseFakeQueue();

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::SUCCESS, $output);

    expect(Post::query()->whereKey($orphan->id)->exists())->toBeFalse()
        ->and(PostPlatform::query()->whereKey($deadTarget->id)->exists())->toBeFalse()
        ->and(Post::query()->where('workspace_id', $workspace->id)->count())->toBe(7)
        ->and(Media::query()->where('collection', Media::LIBRARY_COLLECTION)->exists())->toBeFalse();

    foreach ($libraryPaths as $path) {
        Storage::assertMissing($path);
    }

    foreach ($targets as $id => [$postId, $accountId, $enabled, $status, $contentType, $meta]) {
        $row = PostPlatform::query()->findOrFail($id);
        $original = $originals[$postId];
        $owner = $row->post;

        expect([$row->social_account_id, $row->enabled, $row->status, $row->content_type, $row->meta])->toEqual([$accountId, $enabled, $status, $contentType, $meta])
            ->and($owner->content)->toBe($original['content'])
            ->and($owner->status)->toBe($original['status']->isSettled() ? ($status === PostPlatformStatus::Published ? Status::Published : Status::Failed) : $original['status'])
            ->and($owner->scheduled_at?->toIso8601String())->toBe($original['scheduled_at'])
            ->and($owner->labels()->pluck('workspace_labels.id')->sort()->values()->all())->toBe($original['labels']);

        foreach ($owner->media as $index => $item) {
            $row = Media::query()->findOrFail($item['id']);

            expect(Arr::except($item, ['id', 'path', 'url']))->toEqual($original['items'][$index]['fields'])
                ->and($row->post_id)->toBe($owner->id)
                ->and($row->workspace_id)->toBe($owner->workspace_id)
                ->and($row->path)->toBe($item['path'])
                ->and(Storage::get($item['path']))->toBe($original['items'][$index]['bytes']);
        }

        expect($owner->media)->toHaveCount(count($original['items']));
    }

    foreach ([$draft, $scheduled] as $legacy) {
        $group = Post::query()->where('post_group_id', $legacy->fresh()->post_group_id)->get();

        expect($legacy->fresh()->post_group_id)->not->toBeNull()
            ->and($group)->toHaveCount(2)
            ->and($group->every(fn (Post $post): bool => $post->postPlatforms()->enabled()->count() === 1))->toBeTrue();
    }

    expect(PostPlatform::query()->findOrFail($disabled->id)->post_id)->toBe($scheduled->id)
        ->and(PostNote::query()->whereIn('post_id', Post::query()->where('post_group_id', $scheduled->fresh()->post_group_id)->select('id'))->pluck('body')->all())->toBe([$note->body, $note->body])
        ->and($published->fresh()->post_group_id)->not->toBeNull()
        ->and(Post::query()->where('post_group_id', $published->fresh()->post_group_id)->pluck('status')->map->value->sort()->values()->all())->toBe([Status::Failed->value, Status::Published->value])
        ->and(Post::query()->where('status', Status::PartiallyPublished)->exists())->toBeFalse()
        ->and($mixed->fresh()->postPlatforms()->pluck('id')->all())->toBe([$liveTarget->id])
        ->and($otherPost->fresh()->media)->toEqual([releaseLibraryItem($otherMedia)])
        ->and($otherMedia->fresh()->post_id)->toBe($otherPost->id);
    Storage::assertExists($otherMedia->path);

    Media::query()->each(fn (Media $media) => Storage::assertExists($media->path));

    foreach ($accounts as $account) {
        $account = $account->fresh();

        expect($account->timezone)->toBe('America/Sao_Paulo')
            ->and($account->posting_goal)->toBe(3)
            ->and($account->posting_schedule->slotCount())->toBe(3);
    }

    $fingerprint = releaseFingerprint();

    [$code, $output] = runRelease();

    expect($code)->toBe(Command::SUCCESS, $output)
        ->and(releaseFingerprint())->toEqual($fingerprint);
})->with([
    'library adopted by the release' => [false],
    'library already adopted on boot, before the split' => [true],
]);
