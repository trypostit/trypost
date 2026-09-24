<?php

declare(strict_types=1);

use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Jobs\Analytics\FinalizeAccountDailySnapshot;
use App\Jobs\Analytics\FinalizeAccountDailySnapshots;
use App\Jobs\Analytics\ScheduleInstagramStoryMetrics;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\AnalyticsJobLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

test('horizon supervises every analytics job on a dedicated queue', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

    expect(config('horizon.defaults.analytics.queue'))->toBe(['analytics'])
        ->and(config('horizon.defaults.analytics.connection'))->toBe('redis')
        ->and(config('horizon.environments.production.analytics.maxProcesses'))->toBeGreaterThan(0)
        ->and(config('horizon.environments.local.analytics.maxProcesses'))->toBeGreaterThan(0)
        ->and(implode(' ', $composer['scripts']['dev']))->toContain('--queue=default,analytics');

    foreach ([
        new BackfillAccountPublications('account', 'state'),
        new BackfillTryPostPublications(['destination']),
        new BootstrapAccountAnalytics('account'),
        new CollectAccountDailySnapshot('account', '2026-09-23'),
        new CollectPublicationMetrics('publication', '2026-09-23'),
        new DiscoverAccountPublications('account', 'state'),
        new FinalizeAccountDailySnapshot('account', '2026-09-23'),
        new FinalizeAccountDailySnapshots('2026-09-23'),
        new ScheduleInstagramStoryMetrics('publication'),
        new SyncTryPostPublication(new TryPostPublicationIdentity(
            workspaceId: 'workspace',
            socialAccountId: 'account',
            socialAccountKey: 'account',
            network: 'x',
            platformUserId: 'profile',
            platform: Platform::X,
            accountDisplayName: null,
            accountUsername: null,
            accountAvatarUrl: null,
        ), 'destination'),
    ] as $job) {
        expect($job->queue)->toBe('analytics');
    }
});

test('queue visibility outlasts analytics job timeouts on supported queue drivers', function () {
    $longestAnalyticsJob = max(
        (new BackfillAccountPublications('account', 'state'))->timeout,
        (new CollectAccountDailySnapshot('account', '2026-09-23'))->timeout,
        (new CollectPublicationMetrics('publication', '2026-09-23'))->timeout,
        (new FinalizeAccountDailySnapshots('2026-09-23'))->timeout,
    );

    foreach (['database', 'beanstalkd', 'redis'] as $connection) {
        expect(config("queue.connections.{$connection}.retry_after"))->toBeGreaterThan($longestAnalyticsJob);
    }
});

test('analytics log contexts identify the work without exposing credentials or raw responses', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create([
        'access_token' => 'secret-access-token',
        'refresh_token' => 'secret-refresh-token',
    ]);

    Log::spy();
    app(AnalyticsJobLog::class)->record($account, 'followers', '2026-09-23', 2, 'rate_limited');

    Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) use ($account): bool {
        expect($message)->toBe('analytics.collection')
            ->and($context['workspace_id'])->toBe($account->workspace_id)
            ->and($context['social_account_key'])->toBe($account->id)
            ->and($context['platform'])->toBe($account->platform->value)
            ->and($context['collector'])->toBe('followers')
            ->and($context['date_or_cursor'])->toBe('2026-09-23')
            ->and($context['attempt'])->toBe(2)
            ->and($context['category'])->toBe('rate_limited')
            ->and(json_encode($context))->not->toContain('secret-access-token', 'secret-refresh-token');

        return true;
    });
});
