<?php

declare(strict_types=1);

use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create(['created_at' => now()->subDays(10)]);
});

test('published entries are the ones dated today or earlier', function () {
    $today = ChangelogEntry::factory()->create(['published_on' => today()]);
    $beforeSignup = ChangelogEntry::factory()->create(['published_on' => today()->subDays(11)]);
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);

    expect(ChangelogEntry::published()->pluck('id')->sort()->values()->all())
        ->toEqual(collect([$today->id, $beforeSignup->id])->sort()->values()->all());
});

test('the card may only announce published entries dated since the signup day', function () {
    $recent = ChangelogEntry::factory()->create(['published_on' => today()->subDays(3)]);
    $signupDay = ChangelogEntry::factory()->create(['published_on' => today()->subDays(10)]);
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);
    ChangelogEntry::factory()->create(['published_on' => today()->subDays(11)]);

    expect(ChangelogEntry::announcedTo($this->user)->pluck('id')->sort()->values()->all())
        ->toEqual(collect([$recent->id, $signupDay->id])->sort()->values()->all());
});

test('an entry the user opened is flagged as seen for that user only', function () {
    $opened = ChangelogEntry::factory()->create();
    $unopened = ChangelogEntry::factory()->create();
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $opened->id, 'user_id' => $this->user->id]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $unopened->id]);

    $seen = ChangelogEntry::withSeenBy($this->user)->get()->pluck('seen', 'id');

    expect((bool) $seen[$opened->id])->toBeTrue()
        ->and((bool) $seen[$unopened->id])->toBeFalse();
});

test('entries are ordered newest first', function () {
    $older = ChangelogEntry::factory()->create(['published_on' => today()->subDays(2)]);
    $newer = ChangelogEntry::factory()->create(['published_on' => today()->subDay()]);

    expect(ChangelogEntry::latestFirst()->pluck('id')->all())->toBe([$newer->id, $older->id]);
});

test('the body renders markdown and strips raw html', function () {
    $entry = ChangelogEntry::factory()->make([
        'body' => "Hello **world**\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))",
    ]);

    expect($entry->bodyHtml())
        ->toContain('<strong>world</strong>')
        ->not->toContain('<script>')
        ->not->toContain('javascript:');
});

test('views go with the user and with the entry', function () {
    $entry = ChangelogEntry::factory()->create();
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $entry->id, 'user_id' => $this->user->id]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $entry->id]);

    $this->user->delete();
    expect(ChangelogEntryView::count())->toBe(1);

    $entry->delete();
    expect(ChangelogEntryView::count())->toBe(0);
});

test('the cover url is the versioned file from the Vite build, or null when the build has no cover', function () {
    $random = Str::lower(Str::random(8));
    $buildDirectory = "changelog-test-build-{$random}";
    $built = ChangelogEntry::factory()->create(['slug' => '2026-10-01-built']);
    $missing = ChangelogEntry::factory()->create(['slug' => '2026-10-02-missing']);

    File::ensureDirectoryExists(public_path($buildDirectory));
    File::put(public_path("{$buildDirectory}/manifest.json"), json_encode([
        'resources/changelog/2026-10-01-built/cover.png' => [
            'file' => 'assets/cover-a1b2c3.png',
            'src' => 'resources/changelog/2026-10-01-built/cover.png',
        ],
    ]));

    $this->withVite();
    Vite::useHotFile(storage_path('framework/testing/no-vite-hot-file'))->useBuildDirectory($buildDirectory);

    try {
        expect($built->coverUrl())->toBe(asset("{$buildDirectory}/assets/cover-a1b2c3.png"))
            ->and($missing->coverUrl())->toBeNull();
    } finally {
        File::deleteDirectory(public_path($buildDirectory));
    }
});
