<?php

declare(strict_types=1);

use App\Actions\Post\PruneExpiredPostHistory;
use App\Enums\Post\Status;
use App\Events\PostDeleted;
use App\Models\AnalyticsPublication;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\WorkspaceLabel;
use App\Support\PostHistoryRetention;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

function pruneHistoryStoredMedia(Post $post): Media
{
    $media = Media::factory()->ownedByPost($post)->create();
    Storage::put($media->path, 'bytes');

    return $media;
}

function pruneHistoryPost(array $attributes = []): Post
{
    return Post::factory()->published()->create(array_merge(['published_at' => now()->subDays(731)], $attributes));
}

test('a post published past the retention is deleted with its media and keeps its analytics publication', function () {
    Event::fake([PostDeleted::class]);

    $post = pruneHistoryPost();
    $platform = PostPlatform::factory()->published()->create(['post_id' => $post->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $post->workspace_id]);
    $post->labels()->attach($label->id);
    $note = PostNote::factory()->create(['post_id' => $post->id]);
    $medias = collect(range(1, 3))->map(fn () => pruneHistoryStoredMedia($post));
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $post->workspace_id,
        'post_platform_id' => $platform->id,
    ]);

    $count = PruneExpiredPostHistory::execute(now()->subDays(730));

    expect($count)->toBe(1)
        ->and(Post::query()->find($post->id))->toBeNull()
        ->and(PostPlatform::query()->find($platform->id))->toBeNull()
        ->and(PostNote::query()->find($note->id))->toBeNull()
        ->and(DB::table('post_workspace_label')->where('post_id', $post->id)->exists())->toBeFalse()
        ->and(Media::query()->where('post_id', $post->id)->exists())->toBeFalse()
        ->and($publication->fresh()->post_platform_id)->toBeNull();

    $medias->each(fn (Media $media) => Storage::assertMissing($media->path));
    Event::assertNotDispatched(PostDeleted::class);
});

test('recent, unfinished and unrelated posts and media survive', function () {
    $recent = pruneHistoryPost(['published_at' => now()->subDays(729)]);
    $draft = Post::factory()->draft()->create(['created_at' => now()->subYears(3)]);
    $scheduled = Post::factory()->scheduled()->create(['created_at' => now()->subYears(3)]);
    $failed = Post::factory()->failed()->create(['created_at' => now()->subYears(3)]);
    $posts = [$recent, $draft, $scheduled, $failed];
    $files = collect($posts)->map(fn (Post $post) => pruneHistoryStoredMedia($post));

    $expired = pruneHistoryPost();
    $expiredFile = pruneHistoryStoredMedia($expired);

    expect(PruneExpiredPostHistory::execute(now()->subDays(730)))->toBe(1);

    foreach ($posts as $post) {
        expect(Post::query()->find($post->id))->not->toBeNull();
    }
    $files->each(fn (Media $media) => Storage::assertExists($media->path));
    Storage::assertMissing($expiredFile->path);
});

test('a partially published post is pruned and a published post without a date is not', function () {
    $partial = pruneHistoryPost(['status' => Status::PartiallyPublished]);
    $undated = Post::factory()->create(['status' => Status::Published, 'published_at' => null]);

    expect(PruneExpiredPostHistory::execute(now()->subDays(730)))->toBe(1)
        ->and(Post::query()->find($partial->id))->toBeNull()
        ->and(Post::query()->find($undated->id))->not->toBeNull();
});

test('more posts than one chunk are all pruned', function () {
    Post::factory()->count(PruneExpiredPostHistory::CHUNK + 5)->published()->create(['published_at' => now()->subDays(731)]);

    expect(PruneExpiredPostHistory::execute(now()->subDays(730)))->toBe(PruneExpiredPostHistory::CHUNK + 5)
        ->and(Post::query()->count())->toBe(0);
});

test('the command honours a configured retention', function () {
    config()->set('trypost.posts.history_retention_days', 30);
    $old = pruneHistoryPost(['published_at' => now()->subDays(31)]);
    $fresh = pruneHistoryPost(['published_at' => now()->subDays(29)]);

    $this->artisan('posts:prune-history')->assertExitCode(0);

    expect(Post::query()->find($old->id))->toBeNull()
        ->and(Post::query()->find($fresh->id))->not->toBeNull();
});

test('an invalid retention is rejected and the command deletes nothing', function (mixed $value) {
    config()->set('trypost.posts.history_retention_days', $value);
    $post = pruneHistoryPost();

    expect(fn () => PostHistoryRetention::days())->toThrow(InvalidArgumentException::class);

    $this->artisan('posts:prune-history')->assertExitCode(1);

    expect(Post::query()->find($post->id))->not->toBeNull();
})->with([0, '0', '', 'abc', -5, null, '1.5', '1e2', ' 1e2 ', '-3']);

test('dry run reports the count and deletes nothing', function () {
    $post = pruneHistoryPost();
    $media = pruneHistoryStoredMedia($post);

    $this->artisan('posts:prune-history --dry-run')
        ->expectsOutputToContain('1 post(s) would be deleted.')
        ->assertExitCode(0);

    expect(Post::query()->find($post->id))->not->toBeNull();
    Storage::assertExists($media->path);
});

test('the prune is scheduled daily next to the webhook log prune', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'posts:prune-history'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});
