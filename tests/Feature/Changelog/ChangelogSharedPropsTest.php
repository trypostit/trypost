<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
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

test('the card gets the newest entry while the user has not opened it', function () {
    $newest = ChangelogEntry::factory()->create([
        'published_on' => today(),
        'title' => 'Newest entry',
        'tags' => [Tag::New, Tag::Beta],
    ]);
    ChangelogEntry::factory()->create(['published_on' => today()->subDay()]);
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('changelog.latest.id', $newest->id)
            ->where('changelog.latest.title', 'Newest entry')
            ->where('changelog.latest.seen', false)
            ->where('changelog.latest.tags', [
                ['value' => 'new', 'label' => 'New'],
                ['value' => 'beta', 'label' => 'Beta'],
            ])
            ->missing('changelogEntries'));
});

test('the card is empty once the newest entry was opened, even with older ones unopened', function () {
    $newest = ChangelogEntry::factory()->create(['published_on' => today()]);
    ChangelogEntry::factory()->create(['published_on' => today()->subDay()]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $newest->id, 'user_id' => $this->user->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('changelog.latest', null));
});

test('a user who signed up today never gets an older entry on the card', function () {
    $this->user->forceFill(['created_at' => now()])->save();
    ChangelogEntry::factory()->create(['published_on' => today()->subDay()]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('changelog.latest', null));
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

test('the modal list loads every published entry, ten per page, newest first, with whether the user opened and liked each one', function () {
    $this->user->forceFill(['created_at' => now()->subDays(2)])->save();
    $entries = collect(range(0, 11))->map(fn (int $daysAgo) => ChangelogEntry::factory()->create([
        'published_on' => today()->subDays($daysAgo),
    ]));
    ChangelogEntry::factory()->create(['published_on' => today()->addDay()]);
    ChangelogEntryView::factory()->create([
        'changelog_entry_id' => $entries[1]->id,
        'user_id' => $this->user->id,
        'reaction' => Reaction::Like,
    ]);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $entries[1]->id, 'reaction' => Reaction::Dislike]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('changelogEntries', fn (AssertableInertia $reload) => $reload
                ->has('changelogEntries.data', 10)
                ->where('changelogEntries.data.0.id', $entries[0]->id)
                ->where('changelogEntries.data.1.seen', true)
                ->where('changelogEntries.data.0.seen', false)
                ->where('changelogEntries.data.1.reaction', 'like')
                ->where('changelogEntries.data.0.reaction', null)));

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar', ['changelog_page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->reloadOnly('changelogEntries', fn (AssertableInertia $reload) => $reload
                ->has('changelogEntries.data', 2)
                ->where('changelogEntries.data.1.id', $entries[11]->id)));
});
