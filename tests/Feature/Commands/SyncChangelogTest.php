<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

    $this->directory = storage_path('framework/testing/changelog-'.Str::random(8));
    File::copyDirectory(base_path('tests/fixtures/changelog'), $this->directory);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('it stores every entry and uploads its cover to the default disk', function () {
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();

    $first = ChangelogEntry::where('slug', '2026-10-01-first')->firstOrFail();
    $second = ChangelogEntry::where('slug', '2026-10-02-second')->firstOrFail();

    expect($first->title)->toBe('First entry')
        ->and($first->tag)->toBeNull()
        ->and($first->body)->toBe('The first **entry**.')
        ->and($first->published_on->toDateString())->toBe('2026-10-01')
        ->and($first->cover_path)->toBe('changelog/2026-10-01-first.png')
        ->and($first->cover_hash)->toBe(sha1_file("{$this->directory}/2026-10-01-first/cover.png"))
        ->and($second->tag)->toBe(Tag::Beta);

    Storage::assertExists('changelog/2026-10-01-first.png');
    Storage::assertExists('changelog/2026-10-02-second.png');
});

test('running it again changes nothing and uploads nothing', function () {
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();
    $updatedAt = ChangelogEntry::pluck('updated_at', 'slug');
    Storage::delete('changelog/2026-10-01-first.png');

    $this->travel(1)->minute();
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();

    Storage::assertMissing('changelog/2026-10-01-first.png');
    expect(ChangelogEntry::pluck('updated_at', 'slug')->map->toIso8601String()->all())
        ->toEqual($updatedAt->map->toIso8601String()->all());
});

test('a new cover is uploaded again and a new title is updated', function () {
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();

    File::copy("{$this->directory}/2026-10-02-second/cover.png", "{$this->directory}/2026-10-01-first/cover.png");
    File::put("{$this->directory}/2026-10-01-first/entry.md", "---\ntitle: Renamed entry\ndate: 2026-10-01\n---\n\nBody.\n");

    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();

    $first = ChangelogEntry::where('slug', '2026-10-01-first')->firstOrFail();

    expect($first->title)->toBe('Renamed entry')
        ->and($first->cover_hash)->toBe(sha1_file("{$this->directory}/2026-10-02-second/cover.png"));
    expect(Storage::get('changelog/2026-10-01-first.png'))
        ->toBe(File::get("{$this->directory}/2026-10-02-second/cover.png"));
});

test('an entry whose folder is gone is deleted with its cover and views', function () {
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();
    ChangelogEntryView::factory()->create([
        'changelog_entry_id' => ChangelogEntry::where('slug', '2026-10-01-first')->value('id'),
    ]);

    File::deleteDirectory("{$this->directory}/2026-10-01-first");
    $this->artisan('changelog:sync', ['--path' => $this->directory])->assertSuccessful();

    expect(ChangelogEntry::pluck('slug')->all())->toBe(['2026-10-02-second'])
        ->and(ChangelogEntryView::count())->toBe(0);
    Storage::assertMissing('changelog/2026-10-01-first.png');
});

test('an invalid entry fails the sync, names its folder and writes nothing', function (Closure $break) {
    $break("{$this->directory}/2026-10-02-second");

    $this->artisan('changelog:sync', ['--path' => $this->directory])
        ->expectsOutputToContain('2026-10-02-second')
        ->assertFailed();

    expect(ChangelogEntry::count())->toBe(0);
    Storage::assertMissing('changelog/2026-10-01-first.png');
})->with([
    'missing title' => [fn (string $folder) => File::put("{$folder}/entry.md", "---\ndate: 2026-10-02\n---\n\nBody.\n")],
    'invalid date' => [fn (string $folder) => File::put("{$folder}/entry.md", "---\ntitle: Second\ndate: next week\n---\n\nBody.\n")],
    'unknown tag' => [fn (string $folder) => File::put("{$folder}/entry.md", "---\ntitle: Second\ndate: 2026-10-02\ntag: hot\n---\n\nBody.\n")],
    'empty body' => [fn (string $folder) => File::put("{$folder}/entry.md", "---\ntitle: Second\ndate: 2026-10-02\n---\n")],
    'missing cover' => [fn (string $folder) => File::delete("{$folder}/cover.png")],
    'missing entry file' => [fn (string $folder) => File::delete("{$folder}/entry.md")],
]);
