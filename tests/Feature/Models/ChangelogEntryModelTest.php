<?php

declare(strict_types=1);

use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['created_at' => now()->subDays(10)]);
});

test('a user sees entries published between their signup day and today', function () {
    $visible = ChangelogEntry::factory()->create(['published_on' => today()->subDays(3)]);
    $signupDay = ChangelogEntry::factory()->create(['published_on' => today()->subDays(10)]);
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);
    ChangelogEntry::factory()->create(['published_on' => today()->subDays(11)]);

    expect(ChangelogEntry::visibleTo($this->user)->pluck('id')->sort()->values()->all())
        ->toEqual(collect([$visible->id, $signupDay->id])->sort()->values()->all());
});

test('an entry the user opened is no longer unseen and is flagged as seen', function () {
    $opened = ChangelogEntry::factory()->create();
    $unopened = ChangelogEntry::factory()->create();
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $opened->id, 'user_id' => $this->user->id]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $unopened->id]);

    expect(ChangelogEntry::unseenBy($this->user)->pluck('id')->all())->toBe([$unopened->id]);

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
