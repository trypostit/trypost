<?php

declare(strict_types=1);

use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackChangelogEntryViewed;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake();
});

test('job is queued on the posthog queue', function () {
    expect((new TrackChangelogEntryViewed((string) Str::uuid()))->queue)->toBe('posthog');
});

test('handle captures changelog.entry_viewed for the viewer with the entry and account group', function () {
    $user = User::factory()->create();
    $entry = ChangelogEntry::factory()->create(['title' => 'Music for reels', 'published_on' => '2026-10-14']);
    $view = ChangelogEntryView::factory()->create(['changelog_entry_id' => $entry->id, 'user_id' => $user->id]);

    (new TrackChangelogEntryViewed($view->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && data_get($job->payload, 'event') === ChangelogEvent::EntryViewed->value
        && data_get($job->payload, 'distinctId') === (string) $user->id
        && data_get($job->payload, 'properties.slug') === $entry->slug
        && data_get($job->payload, 'properties.title') === 'Music for reels'
        && data_get($job->payload, 'properties.published_on') === '2026-10-14'
        && data_get($job->payload, 'properties.$groups.account') === (string) $user->account_id);
});

test('handle does nothing when the view is gone or PostHog is off', function (Closure $setup) {
    $view = ChangelogEntryView::factory()->create();
    $setup($view);

    (new TrackChangelogEntryViewed($view->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
})->with([
    'view deleted' => [fn (ChangelogEntryView $view) => $view->delete()],
    'posthog off' => [fn () => config(['services.posthog.enabled' => false])],
]);
