<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\TrackChangelogEntryEvent;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config([
        'trypost.changelog.enabled' => true,
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test',
    ]);
    Bus::fake();

    $this->user = User::factory()->create(['created_at' => now()->subDays(30)]);
    $this->entry = ChangelogEntry::factory()->create(['title' => 'Music for reels']);
    ChangelogEntryView::factory()->create(['changelog_entry_id' => $this->entry->id, 'user_id' => $this->user->id]);
});

function queuedChangelogReactions(): array
{
    return Bus::dispatched(TrackChangelogEntryEvent::class, fn (TrackChangelogEntryEvent $job): bool => $job->event === ChangelogEvent::EntryReacted)
        ->map(fn (TrackChangelogEntryEvent $job): ?string => $job->reaction?->value)
        ->values()
        ->all();
}

test('a reaction is stored on the view and every change queues a PostHog event', function () {
    foreach (['like', 'like', 'dislike', null] as $reaction) {
        $this->actingAs($this->user)
            ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => $reaction])
            ->assertNoContent();
    }

    expect(ChangelogEntryView::sole()->reaction)->toBeNull()
        ->and(queuedChangelogReactions())->toBe(['like', 'dislike', null]);
});

test('reacting to an entry the user has not opened records the view too', function () {
    ChangelogEntryView::query()->delete();

    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => 'like'])
        ->assertNoContent();

    expect(ChangelogEntryView::sole())
        ->user_id->toBe($this->user->id)
        ->reaction->toBe(Reaction::Like);
    Bus::assertDispatched(TrackChangelogEntryEvent::class, fn (TrackChangelogEntryEvent $job): bool => $job->viewId === ChangelogEntryView::sole()->id
        && $job->event === ChangelogEvent::EntryViewed);
});

test('an unknown or missing reaction is rejected', function (array $payload) {
    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reaction');

    expect(ChangelogEntryView::sole()->reaction)->toBeNull()
        ->and(queuedChangelogReactions())->toBe([]);
})->with([
    'unknown' => [['reaction' => 'love']],
    'missing' => [[]],
]);

test('an entry the user cannot see is not found', function (Closure $setup) {
    $setup($this->entry);

    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => 'like'])
        ->assertNotFound();

    expect(queuedChangelogReactions())->toBe([]);
})->with([
    'changelog turned off' => [fn () => config(['trypost.changelog.enabled' => false])],
    'published in the future' => [fn (ChangelogEntry $entry) => $entry->update(['published_on' => today()->addDay()])],
]);

test('guests cannot react', function () {
    $this->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => 'like'])->assertUnauthorized();

    expect(ChangelogEntryView::sole()->reaction)->toBeNull();
});
