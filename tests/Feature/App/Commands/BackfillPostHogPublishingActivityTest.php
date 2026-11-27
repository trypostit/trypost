<?php

declare(strict_types=1);

use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\Account;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
});

test('command queues publishing activity for every existing account', function () {
    $accounts = Account::factory()->count(3)->create();
    Bus::fake([SyncAccountPublishingActivity::class]);

    $this->artisan('posthog:backfill-publishing-activity')
        ->expectsOutputToContain('Queued publishing activity sync for 3 accounts.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncAccountPublishingActivity::class, 3);

    foreach ($accounts as $account) {
        Bus::assertDispatched(
            SyncAccountPublishingActivity::class,
            fn (SyncAccountPublishingActivity $job): bool => $job->accountId === (string) $account->id,
        );
    }
});

test('command does not queue jobs when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Account::factory()->create();
    Bus::fake([SyncAccountPublishingActivity::class]);

    $this->artisan('posthog:backfill-publishing-activity')
        ->expectsOutputToContain('PostHog is disabled; no accounts were queued.')
        ->assertSuccessful();

    Bus::assertNotDispatched(SyncAccountPublishingActivity::class);
});
