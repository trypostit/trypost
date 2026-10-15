<?php

declare(strict_types=1);

use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackChangelogEntryReacted;
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
    expect((new TrackChangelogEntryReacted((string) Str::uuid(), 'like'))->queue)->toBe('posthog');
});

test('handle captures changelog.entry_reacted with the reaction it was given', function (?string $reaction) {
    $user = User::factory()->create();
    $entry = ChangelogEntry::factory()->create();
    $view = ChangelogEntryView::factory()->create([
        'changelog_entry_id' => $entry->id,
        'user_id' => $user->id,
        'reaction' => 'dislike',
    ]);

    (new TrackChangelogEntryReacted($view->id, $reaction))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && data_get($job->payload, 'event') === ChangelogEvent::EntryReacted->value
        && data_get($job->payload, 'distinctId') === (string) $user->id
        && data_get($job->payload, 'properties.slug') === $entry->slug
        && data_get($job->payload, 'properties.reaction') === $reaction
        && data_get($job->payload, 'properties.$groups.account') === (string) $user->account_id);
})->with(['like' => ['like'], 'cleared' => [null]]);

test('handle does nothing when the view is gone', function () {
    $view = ChangelogEntryView::factory()->create();
    $view->delete();

    (new TrackChangelogEntryReacted($view->id, 'like'))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});
