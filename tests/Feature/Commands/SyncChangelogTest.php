<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->directory = storage_path('framework/testing/changelog-'.Str::random(8));
    File::copyDirectory(base_path('tests/fixtures/changelog'), $this->directory);

    config(['trypost.changelog.path' => $this->directory]);
    $this->sync = fn () => $this->artisan('changelog:sync');
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('it stores every entry', function () {
    ($this->sync)()->assertSuccessful();

    $first = ChangelogEntry::where('slug', '2026-10-01-first')->firstOrFail();
    $second = ChangelogEntry::where('slug', '2026-10-02-second')->firstOrFail();

    expect($first->title)->toBe('First entry')
        ->and($first->tags->all())->toBe([])
        ->and($first->body)->toBe('The first **entry**.')
        ->and($first->published_on->toDateString())->toBe('2026-10-01')
        ->and($second->tags->all())->toBe([Tag::New, Tag::Beta]);
});

test('running it again changes nothing', function () {
    ($this->sync)()->assertSuccessful();
    $updatedAt = ChangelogEntry::pluck('updated_at', 'slug');

    $this->travel(1)->minute();
    ($this->sync)()->expectsOutputToContain('0 created, 0 updated, 0 deleted')->assertSuccessful();

    expect(ChangelogEntry::pluck('updated_at', 'slug')->map->toIso8601String()->all())
        ->toEqual($updatedAt->map->toIso8601String()->all());
});

test('a new title is updated', function () {
    ($this->sync)()->assertSuccessful();

    File::put("{$this->directory}/2026-10-01-first/entry.md", "---\ntitle: Renamed entry\ndate: 2026-10-01\n---\n\nBody.\n");

    ($this->sync)()->assertSuccessful();

    expect(ChangelogEntry::where('slug', '2026-10-01-first')->value('title'))->toBe('Renamed entry');
});

test('an entry whose folder is gone is deleted with its views', function () {
    ($this->sync)()->assertSuccessful();
    ChangelogEntryView::factory()->create([
        'changelog_entry_id' => ChangelogEntry::where('slug', '2026-10-01-first')->value('id'),
    ]);

    File::deleteDirectory("{$this->directory}/2026-10-01-first");
    ($this->sync)()->assertSuccessful();

    expect(ChangelogEntry::pluck('slug')->all())->toBe(['2026-10-02-second'])
        ->and(ChangelogEntryView::count())->toBe(0);
});

test('an invalid entry fails the sync, names it and writes nothing', function (Closure $break) {
    $break("{$this->directory}/2026-10-02-second/entry.md", "{$this->directory}/2026-10-02-second/cover.png");

    ($this->sync)()
        ->expectsOutputToContain('2026-10-02-second')
        ->assertFailed();

    expect(ChangelogEntry::count())->toBe(0);
})->with([
    'missing title' => [fn (string $entry) => File::put($entry, "---\ndate: 2026-10-02\n---\n\nBody.\n")],
    'invalid date' => [fn (string $entry) => File::put($entry, "---\ntitle: Second\ndate: next week\n---\n\nBody.\n")],
    'unknown tag' => [fn (string $entry) => File::put($entry, "---\ntitle: Second\ndate: 2026-10-02\ntags: [new, hot]\n---\n\nBody.\n")],
    'repeated tag' => [fn (string $entry) => File::put($entry, "---\ntitle: Second\ndate: 2026-10-02\ntags: [new, new]\n---\n\nBody.\n")],
    'empty body' => [fn (string $entry) => File::put($entry, "---\ntitle: Second\ndate: 2026-10-02\n---\n")],
    'cover not 16:9' => [fn (string $entry, string $cover) => File::put($cover, UploadedFile::fake()->image('cover.png', 1200, 750)->getContent())],
    'missing cover' => [fn (string $entry, string $cover) => File::delete($cover)],
    'missing entry file' => [fn (string $entry) => File::delete($entry)],
]);
