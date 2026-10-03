<?php

declare(strict_types=1);

use App\Actions\Media\AuditMedia;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\RssFeedItem;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
});

function auditStored(Media $media): Media
{
    Storage::put($media->path, 'bytes');

    return $media;
}

function auditSeedClean(): array
{
    $post = Post::factory()->create();
    $postMedia = auditStored(Media::factory()->ownedByPost($post)->create(['path' => 'medias/'.Str::uuid().'.jpg']));
    $post->update(['media' => [['id' => $postMedia->id, 'path' => $postMedia->path]]]);

    $idea = Idea::factory()->create(['workspace_id' => $post->workspace_id]);
    $ideaMedia = auditStored(Media::factory()->ownedByIdea($idea)->create(['path' => 'medias/'.Str::uuid().'.jpg']));
    $idea->update(['media' => [['id' => $ideaMedia->id, 'path' => $ideaMedia->path]]]);

    $item = RssFeedItem::factory()->create();
    $itemMedia = auditStored(Media::factory()->ownedByFeedItem($item)->create(['path' => 'medias/'.Str::uuid().'.jpg']));

    $library = auditStored(Media::factory()->libraryAsset($post->workspace)->create(['path' => 'medias/'.Str::uuid().'.jpg']));
    $upload = auditStored(Media::factory()->temporaryUpload($post->workspace)->create(['path' => 'medias/'.Str::uuid().'.jpg']));

    return compact('post', 'postMedia', 'idea', 'ideaMedia', 'item', 'itemMedia', 'library', 'upload');
}

function auditInsertRaw(array $attributes): string
{
    $id = (string) Str::uuid();

    DB::table('medias')->insert(array_merge([
        'id' => $id,
        'collection' => 'media',
        'type' => 'image',
        'path' => 'medias/'.Str::uuid().'.jpg',
        'original_filename' => 'raw.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 10,
        'order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    Storage::put(DB::table('medias')->where('id', $id)->value('path'), 'bytes');

    return $id;
}

test('a clean workspace reports nothing and exits zero', function () {
    auditSeedClean();

    foreach (AuditMedia::execute() as $findings) {
        expect($findings)->toBe([]);
    }

    $this->artisan('media:audit')->assertExitCode(0);
    $this->artisan('media:audit --json')->assertExitCode(0);
});

test('an orphaned file is reported', function () {
    auditSeedClean();
    Storage::put('medias/orphan.jpg', 'bytes');

    expect(AuditMedia::execute()['orphaned_files'])->toBe([['path' => 'medias/orphan.jpg']]);
    $this->artisan('media:audit')->assertExitCode(1);
});

test('a row whose file is missing is reported', function () {
    $seed = auditSeedClean();
    Storage::delete($seed['itemMedia']->path);

    expect(AuditMedia::execute()['missing_files'])->toBe([['media_id' => $seed['itemMedia']->id, 'path' => $seed['itemMedia']->path]]);
});

test('the audit can skip the per-row file check', function () {
    $seed = auditSeedClean();
    Storage::delete($seed['itemMedia']->path);

    expect(AuditMedia::execute(checkMissingFiles: false))->toBe([...AuditMedia::execute(), 'missing_files' => []]);
});

test('a row with two owners or none is reported', function () {
    $seed = auditSeedClean();
    $two = auditInsertRaw(['workspace_id' => $seed['post']->workspace_id, 'post_id' => $seed['post']->id, 'idea_id' => $seed['idea']->id]);
    $none = auditInsertRaw(['workspace_id' => $seed['post']->workspace_id]);

    $findings = collect(AuditMedia::execute()['owner_count'])->keyBy('media_id');

    expect($findings->keys()->sort()->values()->all())->toBe(collect([$two, $none])->sort()->values()->all())
        ->and($findings[$two]['owners'])->toBe('2')
        ->and($findings[$none]['owners'])->toBe('0');
});

test('json drift is reported in both directions', function () {
    $seed = auditSeedClean();
    $ghost = (string) Str::uuid();
    $seed['post']->update(['media' => [['id' => $ghost, 'path' => 'medias/ghost.jpg']]]);
    $seed['idea']->update(['media' => []]);

    $findings = collect(AuditMedia::execute()['json_drift']);

    expect($findings->all())->toEqualCanonicalizing([
        ['owner_type' => 'post', 'owner_id' => $seed['post']->id, 'media_id' => $ghost, 'problem' => 'json_without_row'],
        ['owner_type' => 'post', 'owner_id' => $seed['post']->id, 'media_id' => $seed['postMedia']->id, 'problem' => 'row_without_json'],
        ['owner_type' => 'idea', 'owner_id' => $seed['idea']->id, 'media_id' => $seed['ideaMedia']->id, 'problem' => 'row_without_json'],
    ]);
});

test('a row in another workspace than its owner is reported', function () {
    $seed = auditSeedClean();
    $other = Workspace::factory()->create();
    DB::table('medias')->where('id', $seed['postMedia']->id)->update(['workspace_id' => $other->id]);

    $findings = AuditMedia::execute()['workspace_mismatch'];

    expect($findings)->toHaveCount(1)
        ->and($findings[0])->toBe([
            'media_id' => $seed['postMedia']->id,
            'path' => $seed['postMedia']->path,
            'owner' => 'post',
            'media_workspace_id' => $other->id,
            'owner_workspace_id' => $seed['post']->workspace_id,
        ]);
});

test('a temporary upload past retention is reported and a fresh one is not', function () {
    $seed = auditSeedClean();
    $stale = auditStored(Media::factory()->temporaryUpload($seed['post']->workspace)->create([
        'path' => 'medias/'.Str::uuid().'.jpg',
        'created_at' => now()->subHours(30),
    ]));

    $findings = AuditMedia::execute()['stale_uploads'];

    expect($findings)->toHaveCount(1)
        ->and($findings[0]['media_id'])->toBe($stale->id);
    $this->artisan('media:audit --json')->assertExitCode(1);
});
