<?php

declare(strict_types=1);

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
});

test('opening an entry records the view once and sends it to PostHog once', function () {
    $this->actingAs($this->user)
        ->post(route('app.changelog.views.store', $this->entry))
        ->assertNoContent();

    $this->actingAs($this->user)
        ->post(route('app.changelog.views.store', $this->entry))
        ->assertNoContent();

    expect(ChangelogEntryView::where('user_id', $this->user->id)->pluck('changelog_entry_id')->all())
        ->toBe([$this->entry->id]);

    Bus::assertDispatchedTimes(SendEvent::class, 1);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === ChangelogEvent::EntryViewed->value
        && data_get($event->payload, 'properties.slug') === $this->entry->slug
        && data_get($event->payload, 'properties.title') === 'Music for reels');
});

test('an entry the user cannot see is not found', function (Closure $setup) {
    $setup($this->entry);

    $this->actingAs($this->user)
        ->post(route('app.changelog.views.store', $this->entry))
        ->assertNotFound();

    expect(ChangelogEntryView::count())->toBe(0);
    Bus::assertNotDispatched(SendEvent::class);
})->with([
    'changelog turned off' => [fn () => config(['trypost.changelog.enabled' => false])],
    'published in the future' => [fn (ChangelogEntry $entry) => $entry->update(['published_on' => today()->addDay()])],
]);

test('an entry published before the user signed up can still be opened from the list', function () {
    $this->entry->update(['published_on' => today()->subDays(31)]);

    $this->actingAs($this->user)
        ->post(route('app.changelog.views.store', $this->entry))
        ->assertNoContent();

    expect(ChangelogEntryView::where('user_id', $this->user->id)->count())->toBe(1);
});

test('guests cannot record a view', function () {
    $this->post(route('app.changelog.views.store', $this->entry))->assertRedirect(route('login'));

    expect(ChangelogEntryView::count())->toBe(0);
});
