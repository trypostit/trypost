<?php

declare(strict_types=1);

use App\Actions\Changelog\ShowChangelogCard;
use App\Actions\Changelog\SyncChangelogEntries;
use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

function writeSyncChangelogEntry(string $folder, string $markdown): void
{
    File::ensureDirectoryExists($folder);
    File::put("{$folder}/entry.md", $markdown);
    File::put("{$folder}/cover.png", UploadedFile::fake()->image('cover.png', 1600, 900)->getContent());
}

beforeEach(function () {
    $random = Str::random(8);
    $this->directory = storage_path("framework/testing/changelog-{$random}");
    writeSyncChangelogEntry("{$this->directory}/2026-10-01-first", "---\ntitle: First entry\ndate: 2026-10-01\n---\n\nThe first **entry**.\n");
    writeSyncChangelogEntry("{$this->directory}/2026-10-02-second", "---\ntitle: Second entry\ndate: 2026-10-02\ntags: [new, beta]\n---\n\nThe second entry.\n");

    config(['trypost.changelog.path' => $this->directory]);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

test('it stores every entry', function () {
    $this->artisan('changelog:sync')->assertSuccessful();

    $first = ChangelogEntry::where('slug', '2026-10-01-first')->firstOrFail();
    $second = ChangelogEntry::where('slug', '2026-10-02-second')->firstOrFail();

    expect($first->title)->toBe('First entry')
        ->and($first->tags->all())->toBe([])
        ->and($first->body)->toBe('The first **entry**.')
        ->and($first->published_on->toDateString())->toBe('2026-10-01')
        ->and($second->tags->all())->toBe([Tag::New, Tag::Beta]);
});

test('running it again changes nothing', function () {
    $this->artisan('changelog:sync')->assertSuccessful();
    $updatedAt = ChangelogEntry::pluck('updated_at', 'slug');

    $this->travel(1)->minute();
    $this->artisan('changelog:sync')->expectsOutputToContain('0 created, 0 updated, 0 deleted')->assertSuccessful();

    expect(ChangelogEntry::pluck('updated_at', 'slug')->map->toIso8601String()->all())
        ->toEqual($updatedAt->map->toIso8601String()->all());
});

test('a changed title, tags or body is counted and saved as an update', function () {
    $this->artisan('changelog:sync')->expectsOutputToContain('2 created, 0 updated, 0 deleted')->assertSuccessful();

    File::put("{$this->directory}/2026-10-01-first/entry.md", "---\ntitle: Renamed entry\ndate: 2026-10-01\ntags: [fixed]\n---\n\nNew body.\n");

    $this->artisan('changelog:sync')->expectsOutputToContain('0 created, 1 updated, 0 deleted')->assertSuccessful();

    expect(ChangelogEntry::where('slug', '2026-10-01-first')->sole())
        ->title->toBe('Renamed entry')
        ->body->toBe('New body.')
        ->tags->all()->toBe([Tag::Fixed]);
});

test('an entry whose folder is gone is deleted with its views', function () {
    $this->artisan('changelog:sync')->assertSuccessful();
    ChangelogEntryView::factory()->create([
        'changelog_entry_id' => ChangelogEntry::where('slug', '2026-10-01-first')->value('id'),
    ]);

    File::deleteDirectory("{$this->directory}/2026-10-01-first");
    $this->artisan('changelog:sync')->assertSuccessful();

    expect(ChangelogEntry::pluck('slug')->all())->toBe(['2026-10-02-second'])
        ->and(ChangelogEntryView::count())->toBe(0);
});

test('an invalid entry fails the sync, names it and writes nothing', function (Closure $break) {
    $break("{$this->directory}/2026-10-02-second/entry.md", "{$this->directory}/2026-10-02-second/cover.png");

    $this->artisan('changelog:sync')
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
    'missing date' => [fn (string $entry) => File::put($entry, "---\ntitle: Second\n---\n\nBody.\n")],
    'title too long' => [fn (string $entry) => File::put($entry, Str::replace('LONG', Str::repeat('a', 256), "---\ntitle: LONG\ndate: 2026-10-02\n---\n\nBody.\n"))],
    'malformed front matter' => [fn (string $entry) => File::put($entry, "---\ntitle: [unclosed\ndate: 2026-10-02\n---\n\nBody.\n")],
]);

test('a missing changelog folder fails the sync and keeps every entry', function () {
    $this->artisan('changelog:sync')->assertSuccessful();
    File::deleteDirectory($this->directory);

    $this->artisan('changelog:sync')
        ->expectsOutputToContain('does not exist')
        ->assertFailed();

    expect(ChangelogEntry::count())->toBe(2);
});

test('the entries shipped in resources/changelog are valid', function () {
    expect(fn () => SyncChangelogEntries::execute(resource_path('changelog')))->not->toThrow(InvalidArgumentException::class);
});

test('a sync that changes nothing keeps the cached cards, and one that changes the entries refreshes them', function () {
    $user = User::factory()->create(['created_at' => now()->subYear()]);
    $this->artisan('changelog:sync')->assertSuccessful();

    expect(ShowChangelogCard::cached($user)?->slug)->toBe('2026-10-02-second');

    ChangelogEntryView::factory()->create([
        'changelog_entry_id' => ChangelogEntry::where('slug', '2026-10-02-second')->value('id'),
        'user_id' => $user->id,
    ]);
    $this->artisan('changelog:sync')->expectsOutputToContain('0 created, 0 updated, 0 deleted')->assertSuccessful();

    expect(ShowChangelogCard::cached($user)?->slug)->toBe('2026-10-02-second');

    writeSyncChangelogEntry("{$this->directory}/2026-10-03-third", "---\ntitle: Third entry\ndate: 2026-10-03\n---\n\nThe third entry.\n");
    $this->artisan('changelog:sync')->assertSuccessful();

    expect(ShowChangelogCard::cached($user)?->slug)->toBe('2026-10-03-third');
});

test('a folder that is not named <YYYY-MM-DD>-<slug> fails the sync and writes nothing', function () {
    File::moveDirectory("{$this->directory}/2026-10-02-second", "{$this->directory}/Launch Day");

    $this->artisan('changelog:sync')
        ->expectsOutputToContain('Launch Day')
        ->assertFailed();

    expect(ChangelogEntry::count())->toBe(0);
});
