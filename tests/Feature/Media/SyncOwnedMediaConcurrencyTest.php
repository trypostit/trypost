<?php

declare(strict_types=1);

use App\Actions\Media\SyncOwnedMedia;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * A second connection adopts the upload and commits after the saving
 * transaction took its snapshot but before it locks the rows. Needs committed
 * data, so the test runs on its own throwaway database (created, migrated and
 * dropped per test) and leaves the shared test database untouched.
 */
beforeEach(function () {
    Storage::fake();

    $this->originalConnection = config('database.default');
    $base = config("database.connections.{$this->originalConnection}");
    $this->concurrencyDatabase = "{$base['database']}_concurrency";

    config()->set([
        'database.connections.concurrency_admin' => $base,
        'database.connections.concurrency' => [...$base, 'database' => $this->concurrencyDatabase],
        'database.connections.winner' => [...$base, 'database' => $this->concurrencyDatabase],
    ]);

    Schema::connection('concurrency_admin')->dropDatabaseIfExists($this->concurrencyDatabase);
    Schema::connection('concurrency_admin')->createDatabase($this->concurrencyDatabase);

    $this->pinnedConnections = [
        'telescope.storage.database.connection' => config('telescope.storage.database.connection'),
        'passport.connection' => config('passport.connection'),
    ];
    config()->set([
        'database.default' => 'concurrency',
        'telescope.storage.database.connection' => 'concurrency',
        'passport.connection' => 'concurrency',
    ]);
    DB::setDefaultConnection('concurrency');
    Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);
});

afterEach(function () {
    config()->set(['database.default' => $this->originalConnection, ...$this->pinnedConnections]);
    DB::setDefaultConnection($this->originalConnection);
    DB::purge('concurrency');
    DB::purge('winner');

    Schema::connection('concurrency_admin')->dropDatabaseIfExists($this->concurrencyDatabase);
    DB::purge('concurrency_admin');
});

function concurrencyFixture(): array
{
    $workspace = Workspace::factory()->create();
    $upload = Media::factory()->temporaryUpload($workspace)->stored()->create();
    Storage::put($upload->path, 'bytes');
    $winner = Post::factory()->create(['workspace_id' => $workspace->id]);
    $loser = Post::factory()->create(['workspace_id' => $workspace->id]);

    return [$workspace, $upload, $winner, $loser];
}

function concurrencySave(Post $loser, array $item, Media $upload, Post $winner): void
{
    MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($loser, $item, $upload, $winner): void {
        DB::table('medias')->count();

        DB::connection('winner')->table('medias')->where('id', $upload->id)->update([
            'post_id' => $winner->id,
            'collection' => Media::COLLECTION_MEDIA,
            'mediable_type' => null,
            'mediable_id' => null,
            'upload_token' => null,
        ]);

        SyncOwnedMedia::execute($loser, [$item], $batch);
    });
}

test('a token adopted by another connection after this save read its snapshot is expired, not stolen', function () {
    [, $upload, $winner, $loser] = concurrencyFixture();

    expect(fn () => concurrencySave($loser, ['id' => $upload->id, 'upload_token' => $upload->upload_token], $upload, $winner))
        ->toThrow(ValidationException::class);

    expect(Media::query()->find($upload->id)->post_id)->toBe($winner->id)
        ->and(Media::query()->where('post_id', $loser->id)->count())->toBe(0);
    Storage::assertExists($upload->path);
});

test('an id adopted by another connection after this save read its snapshot is copied, not stolen', function () {
    [, $upload, $winner, $loser] = concurrencyFixture();

    concurrencySave($loser, ['id' => $upload->id], $upload, $winner);

    $copy = Media::query()->where('post_id', $loser->id)->sole();

    expect(Media::query()->find($upload->id)->post_id)->toBe($winner->id)
        ->and($copy->id)->not->toBe($upload->id)
        ->and($copy->path)->not->toBe($upload->path);
    Storage::assertExists([$upload->path, $copy->path]);
});
