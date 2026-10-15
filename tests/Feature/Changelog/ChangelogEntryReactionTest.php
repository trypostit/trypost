<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\SendEvent;
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

function reactedEvents(): array
{
    return Bus::dispatched(SendEvent::class)
        ->filter(fn (SendEvent $event): bool => data_get($event->payload, 'event') === ChangelogEvent::EntryReacted->value)
        ->map(fn (SendEvent $event): array => [
            'slug' => data_get($event->payload, 'properties.slug'),
            'reaction' => data_get($event->payload, 'properties.reaction'),
        ])
        ->values()
        ->all();
}

test('a reaction is stored on the view and every change goes to PostHog', function () {
    foreach (['like', 'like', 'dislike', null] as $reaction) {
        $this->actingAs($this->user)
            ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => $reaction])
            ->assertNoContent();
    }

    expect(ChangelogEntryView::sole()->reaction)->toBeNull()
        ->and(reactedEvents())->toBe([
            ['slug' => $this->entry->slug, 'reaction' => 'like'],
            ['slug' => $this->entry->slug, 'reaction' => 'dislike'],
            ['slug' => $this->entry->slug, 'reaction' => null],
        ]);
});

test('reacting to an entry the user has not opened records the view too', function () {
    ChangelogEntryView::query()->delete();

    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => 'like'])
        ->assertNoContent();

    expect(ChangelogEntryView::sole())
        ->user_id->toBe($this->user->id)
        ->reaction->toBe(Reaction::Like);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === ChangelogEvent::EntryViewed->value);
});

test('an unknown or missing reaction is rejected', function (array $payload) {
    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reaction');

    expect(ChangelogEntryView::sole()->reaction)->toBeNull()
        ->and(reactedEvents())->toBe([]);
})->with([
    'unknown' => [['reaction' => 'love']],
    'missing' => [[]],
]);

test('an entry the user cannot see is not found', function (Closure $setup) {
    $setup($this->entry);

    $this->actingAs($this->user)
        ->putJson(route('app.changelog.reaction.update', $this->entry), ['reaction' => 'like'])
        ->assertNotFound();

    expect(reactedEvents())->toBe([]);
})->with([
    'changelog turned off' => [fn () => config(['trypost.changelog.enabled' => false])],
    'published in the future' => [fn (ChangelogEntry $entry) => $entry->update(['published_on' => today()->addDay()])],
]);
