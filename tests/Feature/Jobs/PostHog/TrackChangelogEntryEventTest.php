<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackChangelogEntryEvent;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake();

    $this->user = User::factory()->create();
    $this->entry = ChangelogEntry::factory()->create(['title' => 'Music for reels', 'published_on' => '2026-10-14']);
    $this->view = ChangelogEntryView::factory()->create([
        'changelog_entry_id' => $this->entry->id,
        'user_id' => $this->user->id,
        'reaction' => Reaction::Dislike,
    ]);
});

test('job is queued on the posthog queue', function () {
    expect((new TrackChangelogEntryEvent((string) Str::uuid(), ChangelogEvent::EntryViewed))->queue)->toBe('posthog');
});

test('a view captures changelog.entry_viewed for the viewer with the entry and account group', function () {
    (new TrackChangelogEntryEvent($this->view->id, ChangelogEvent::EntryViewed))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && data_get($job->payload, 'event') === ChangelogEvent::EntryViewed->value
        && data_get($job->payload, 'distinctId') === $this->user->id
        && data_get($job->payload, 'properties.slug') === $this->entry->slug
        && data_get($job->payload, 'properties.title') === 'Music for reels'
        && data_get($job->payload, 'properties.published_on') === '2026-10-14'
        && ! array_key_exists('reaction', data_get($job->payload, 'properties'))
        && data_get($job->payload, 'properties.$groups.account') === $this->user->account_id);
});

test('a reaction captures changelog.entry_reacted with the reaction it was given, not the stored one', function (?Reaction $reaction) {
    (new TrackChangelogEntryEvent($this->view->id, ChangelogEvent::EntryReacted, $reaction))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => data_get($job->payload, 'event') === ChangelogEvent::EntryReacted->value
        && data_get($job->payload, 'properties.slug') === $this->entry->slug
        && array_key_exists('reaction', data_get($job->payload, 'properties'))
        && data_get($job->payload, 'properties.reaction') === $reaction?->value);
})->with(['like' => [Reaction::Like], 'cleared' => [null]]);

test('nothing is captured when the view is gone or PostHog is off', function (Closure $setup) {
    $setup($this->view);

    (new TrackChangelogEntryEvent($this->view->id, ChangelogEvent::EntryReacted, Reaction::Like))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
})->with([
    'view deleted' => [fn (ChangelogEntryView $view) => $view->delete()],
    'posthog off' => [fn () => config(['services.posthog.enabled' => false])],
]);
