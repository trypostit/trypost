<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\TrackChangelogEntryEvent;
use App\Models\ChangelogEntryView;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Bus::fake();
});

test('creating a view queues the viewed event and changing the reaction queues the reacted event', function () {
    $view = ChangelogEntryView::factory()->create();
    $view->update(['reaction' => Reaction::Like]);

    expect(Bus::dispatched(TrackChangelogEntryEvent::class)->map(fn (TrackChangelogEntryEvent $job): array => [$job->event, $job->reaction])->all())
        ->toBe([[ChangelogEvent::EntryViewed, null], [ChangelogEvent::EntryReacted, Reaction::Like]]);
});

test('a change to anything but the reaction queues nothing', function () {
    $view = ChangelogEntryView::factory()->create();
    Bus::fake();

    $view->update(['viewed_at' => now()->addMinute()]);

    Bus::assertNotDispatched(TrackChangelogEntryEvent::class);
});

test('nothing is queued while PostHog is off', function () {
    config(['services.posthog.enabled' => false]);

    ChangelogEntryView::factory()->create()->update(['reaction' => Reaction::Like]);

    Bus::assertNotDispatched(TrackChangelogEntryEvent::class);
});
