<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Models\Workspace;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['trypost.changelog.enabled' => true]);

    $this->user = User::factory()->create(['created_at' => now()->subDays(30)]);
    $workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
});

test('the card gets the newest entry the user has not opened', function () {
    $opened = ChangelogEntry::factory()->create(['published_on' => today()]);
    $newestUnseen = ChangelogEntry::factory()->create([
        'published_on' => today()->subDay(),
        'title' => 'Newest unseen',
        'tags' => [Tag::New, Tag::Beta],
    ]);
    ChangelogEntry::factory()->create(['published_on' => today()->subDays(2)]);
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $opened->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('changelog.latestUnseen.id', $newestUnseen->id)
            ->where('changelog.latestUnseen.title', 'Newest unseen')
            ->where('changelog.latestUnseen.seen', false)
            ->where('changelog.latestUnseen.tags', ['new', 'beta'])
            ->missing('changelogEntries'));
});

test('the card is empty once every entry was opened', function () {
    $entry = ChangelogEntry::factory()->create();
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $entry->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('changelog.latestUnseen', null));
});

test('nothing is shared when the changelog is turned off', function () {
    config(['trypost.changelog.enabled' => false]);
    ChangelogEntry::factory()->create();

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('changelog', null)
            ->missing('changelogEntries'));
});

test('the modal list loads five entries per page, newest first, flagging the opened ones', function () {
    $entries = collect(range(0, 6))->map(fn (int $daysAgo) => ChangelogEntry::factory()->create([
        'published_on' => today()->subDays($daysAgo),
    ]));
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $entries[1]->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('changelogEntries', fn (AssertableInertia $reload) => $reload
                ->has('changelogEntries.data', 5)
                ->where('changelogEntries.data.0.id', $entries[0]->id)
                ->where('changelogEntries.data.1.seen', true)
                ->where('changelogEntries.data.0.seen', false)));

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar', ['changelog_page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('changelogEntries', fn (AssertableInertia $reload) => $reload
                ->has('changelogEntries.data', 2)
                ->where('changelogEntries.data.1.id', $entries[6]->id)));
});
