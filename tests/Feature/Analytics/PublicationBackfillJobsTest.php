<?php

declare(strict_types=1);

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\AbstractPublicationSync;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Jobs\Post\ImportExternalPostMedia;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\AbstractPublicationHistoryCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]));

function bindPublicationPage(PublicationPage $page): void
{
    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->andReturn($page);

    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
}

test('publication jobs are provider limited and account overlap protected', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $backfill = new BackfillAccountPublications($account->id, fake()->uuid());
    $discovery = new DiscoverAccountPublications($account->id, fake()->uuid());

    expect(collect($backfill->analyticsRateLimits())->pluck('key')->all())->toBe(["instagram:account:{$account->id}"])
        ->and($backfill->tries)->toBe(0)
        ->and($backfill->maxExceptions)->toBe(6)
        ->and($backfill->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($backfill->middleware()[1])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($discovery->tries)->toBe(0)
        ->and($discovery->maxExceptions)->toBe(6)
        ->and($discovery->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($discovery->middleware()[1])->toBeInstanceOf(WithoutOverlapping::class);
});

test('discovery dispatcher loads sync states once for all accounts in a page', function () {
    $accounts = SocialAccount::factory()->instagram()->count(3)->create();

    foreach ($accounts as $account) {
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => SyncStatus::Complete,
        ]);
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationDiscovery,
        ]);
    }

    Bus::fake();
    $stateReads = [];
    DB::listen(function ($query) use (&$stateReads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')
            && str_contains($query->sql, 'analytics_sync_states')) {
            $stateReads[] = $query->sql;
        }
    });

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertDispatchedTimes(DiscoverAccountPublications::class, 3);
    expect($stateReads)->toHaveCount(1);
});

test('discovery dispatcher resumes failed backfill before incremental discovery', function () {
    $account = SocialAccount::factory()->instagram()->create();
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Failed,
        'last_error_category' => 'queue_failed',
        'checkpoint' => ['cursor' => 'saved-cursor', 'revision' => 3],
    ]);
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
    ]);
    Bus::fake();

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $account->id);
    Bus::assertNotDispatched(DiscoverAccountPublications::class);
});

test('daily discovery does not retry a backfill rejected for missing permission', function () {
    $account = SocialAccount::factory()->instagram()->create();
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Failed,
        'last_error_category' => 'permission',
    ]);
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
    ]);
    Bus::fake();

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);
    Bus::assertNotDispatched(DiscoverAccountPublications::class);
});

test('daily discovery recovers stale bootstraps, bootstraps channels never bootstrapped and leaves delayed retries alone', function () {
    $stalePending = SocialAccount::factory()->instagram()->create();
    $staleRunning = SocialAccount::factory()->instagram()->create();
    $publicMastodon = SocialAccount::factory()->mastodon()->create(['scopes' => ['read:accounts', 'write:statuses']]);
    $recentMastodon = SocialAccount::factory()->mastodon()->create();
    $recentPending = SocialAccount::factory()->instagram()->create();
    $rateLimited = SocialAccount::factory()->instagram()->create();
    $awaitingRollout = SocialAccount::factory()->instagram()->create();
    $readyForDiscovery = SocialAccount::factory()->instagram()->create();

    foreach ([
        [$stalePending, SyncStatus::Pending, null, 3],
        [$staleRunning, SyncStatus::Running, null, 3],
        [$publicMastodon, SyncStatus::Running, 'mastodon_reconnect_for_private_history', 3],
        [$recentMastodon, SyncStatus::Running, 'mastodon_reconnect_for_private_history', 1],
        [$recentPending, SyncStatus::Pending, null, 1],
        [$rateLimited, SyncStatus::Running, 'rate_limited', 3],
        [$readyForDiscovery, SyncStatus::Complete, null, 3],
    ] as [$account, $status, $errorCategory, $hoursAgo]) {
        $state = AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => $status,
            'last_error_category' => $errorCategory,
        ]);
        $state->updated_at = now()->subHours($hoursAgo);
        $state->saveQuietly();
    }

    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($readyForDiscovery),
        'social_account_id' => $readyForDiscovery->id,
        'collector' => SyncCollector::PublicationDiscovery,
    ]);

    Bus::fake();

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    expect(Bus::dispatched(BootstrapAccountAnalytics::class)->pluck('socialAccountId')->sort()->values()->all())
        ->toBe(collect([$stalePending->id, $staleRunning->id, $publicMastodon->id, $awaitingRollout->id])->sort()->values()->all());
    Bus::assertDispatched(DiscoverAccountPublications::class, fn ($job): bool => $job->socialAccountId === $readyForDiscovery->id);
    Bus::assertDispatchedTimes(DiscoverAccountPublications::class, 1);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('bootstrap creates separate backfill and discovery states and dispatches the first page', function () {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-09-23 10:00:00 UTC');
    $account = SocialAccount::factory()->instagram()->create();

    (new BootstrapAccountAnalytics($account->id))->handleFor($account->id);

    $backfill = AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->where('collector', SyncCollector::PublicationBackfill)->firstOrFail();
    $discovery = AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->where('collector', SyncCollector::PublicationDiscovery)->firstOrFail();

    expect($backfill->status)->toBe(SyncStatus::Pending)
        ->and($backfill->target_since?->toDateString())->toBe('2025-09-23')
        ->and($discovery->status)->toBe(SyncStatus::Pending);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->socialAccountId === $account->id && $job->syncStateId === $backfill->id);
});

test('discovery restarts a public-only Mastodon backfill when its existing grant can read privately again', function () {
    $publicScopes = ['read:accounts', 'write:statuses', 'write:media'];
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['read', 'write']]);
    $backfill = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Partial,
        'last_error_category' => 'mastodon_reconnect_for_private_history',
    ]);
    Bus::fake();

    config(['trypost.platforms.mastodon.scopes' => $publicScopes]);
    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();
    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);

    config(['trypost.platforms.mastodon.scopes' => [...$publicScopes, 'read:statuses']]);
    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();
    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $account->id);

    (new BootstrapAccountAnalytics($account->id))->handle();

    expect($backfill->fresh()->status)->toBe(SyncStatus::Pending);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->syncStateId === $backfill->id);
});

test('bootstrap resumes a failed backfill from its last committed cursor', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Failed,
        'checkpoint' => ['cursor' => 'last-committed-page', 'revision' => 3, 'seen_count' => 3100],
    ]);

    (new BootstrapAccountAnalytics($account->id))->handleFor($account->id);

    expect($state->fresh()->status)->toBe(SyncStatus::Pending)
        ->and($state->fresh()->checkpoint)->toMatchArray([
            'cursor' => 'last-committed-page',
            'revision' => 3,
            'seen_count' => 3100,
        ]);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->syncStateId === $state->id);
});

test('re-authorizing the same account starts incremental discovery without restarting complete backfill', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $backfill = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Complete,
    ]);
    $discovery = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    (new BootstrapAccountAnalytics($account->id, true))->handleFor($account->id);

    expect($backfill->fresh()->status)->toBe(SyncStatus::Complete);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class, fn ($job): bool => $job->syncStateId === $discovery->id);
});

test('x backfill reports provider limited when the 800 post timeline without replies ends before the target', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'last-page', 'revision' => 4, 'seen_count' => 799],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('x-800', CarbonImmutable::parse('2026-01-01', 'UTC'), PublicationContentType::Text),
    ], null, true));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and($state->fresh()->last_error_category)->toBe('x_timeline_limit')
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => null, 'seen_count' => 800]);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('x backfill counts the replies the collector skipped toward the timeline cap', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'last-page', 'revision' => 4, 'seen_count' => 798],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('x-root', CarbonImmutable::parse('2026-01-01', 'UTC'), PublicationContentType::Text),
    ], null, true, providerRowCount: 2));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and(data_get($state->fresh()->checkpoint, 'seen_count'))->toBe(800);
});

test('x backfill detects the timeline cap on a last page of skipped replies only', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'last-page', 'revision' => 4, 'seen_count' => 798],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([], null, true, providerRowCount: 2));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and($state->fresh()->last_error_category)->toBe('x_timeline_limit');
});

test('x backfill below the timeline cap completes when the provider exhausts its history', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'last-page', 'revision' => 4, 'seen_count' => 25],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('x-26', CarbonImmutable::parse('2026-01-01', 'UTC'), PublicationContentType::Text),
    ], null, true));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($state->fresh()->last_error_category)->toBeNull()
        ->and(data_get($state->fresh()->checkpoint, 'seen_count'))->toBe(26);
});

test('restarting a terminal x backfill resets its timeline count', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'status' => SyncStatus::ProviderLimited,
        'checkpoint' => ['cursor' => null, 'revision' => 4, 'seen_count' => 800],
    ]);

    $started = app(AdvanceAnalyticsSyncState::class)->begin($state->id, restartTerminal: true);

    expect($started['cursor'])->toBeNull()
        ->and($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => null, 'revision' => 5, 'seen_count' => 0]);
});

test('a backfill job persists one page then advances its cursor and dispatches continuation', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication(
            providerPostId: 'native-1',
            publishedAt: CarbonImmutable::parse('2026-08-01', 'UTC'),
            contentType: PublicationContentType::Image,
        ),
    ], 'next-page', false));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect(AnalyticsPublication::query()->where('remote_id', 'native-1')->exists())->toBeTrue()
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => 'next-page', 'revision' => 1])
        ->and($state->fresh()->status)->toBe(SyncStatus::Running);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->socialAccountId === $account->id && $job->syncStateId === $state->id);
});

test('a paused history backfill retains its cursor and resumes once the scope is available', function () {
    Http::fake([
        config('trypost.platforms.tiktok.api').'/video/list/*' => Http::response([
            'data' => [
                'videos' => [['id' => 'older-post', 'create_time' => now()->subMonth()->timestamp]],
                'has_more' => false,
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);
    $scopes = ['user.info.basic', 'video.publish', 'video.upload'];
    $account = SocialAccount::factory()->create([
        'platform' => Platform::TikTok,
        'scopes' => [...$scopes, 'video.list'],
        'token_expires_at' => now()->addDay(),
    ]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Running,
        'checkpoint' => ['cursor' => '1234', 'revision' => 2],
        'last_success_at' => null,
        'last_error_category' => 'rate_limited',
    ]);
    $job = new BackfillAccountPublications($account->id, $state->id);
    Bus::fake();

    config(['trypost.platforms.tiktok.scopes' => $scopes]);
    app()->call([$job, 'handle']);
    Http::assertNothingSent();
    $job->failed(new RuntimeException('Late queue failure'));
    expect($state->fresh())
        ->status->toBe(SyncStatus::Paused)
        ->checkpoint->toBe(['cursor' => '1234', 'revision' => 3])
        ->last_error_category->toBeNull()
        ->last_success_at->toBeNull();

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();
    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);

    config(['trypost.platforms.tiktok.scopes' => [...$scopes, 'video.list']]);
    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();
    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $account->id);
    (new BootstrapAccountAnalytics($account->id))->handle();
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->syncStateId === $state->id);

    app()->call([$job, 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(AnalyticsPublication::query()->where('remote_id', 'older-post')->exists())->toBeTrue();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => data_get($request->data(), 'cursor') === 1234);
});

test('bootstrapping an account without history permissions leaves its backfill paused without dispatching it', function () {
    config(['trypost.platforms.tiktok.scopes' => ['user.info.basic', 'video.publish', 'video.upload']]);
    $account = SocialAccount::factory()->tiktok()->create();
    Bus::fake();

    (new BootstrapAccountAnalytics($account->id))->handle();

    $backfill = $account->analyticsSyncStates()->forCollector(SyncCollector::PublicationBackfill)->sole();
    expect($backfill->status)->toBe(SyncStatus::Paused);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('a publication page resolves the account identity once for every post', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    $page = new PublicationPage([
        new DiscoveredPublication('native-1', CarbonImmutable::parse('2026-08-01', 'UTC'), PublicationContentType::Image),
        new DiscoveredPublication('native-2', CarbonImmutable::parse('2026-08-02', 'UTC'), PublicationContentType::Image),
    ], null, true);

    $keys = Mockery::mock(ResolveAnalyticsAccountKey::class);
    $keys->shouldReceive('for')->once()->with(Mockery::on(fn (SocialAccount $model): bool => $model->id === $account->id))
        ->andReturn($account->id);
    app()->instance(ResolveAnalyticsAccountKey::class, $keys);

    $sync = app(AdvanceAnalyticsSyncState::class);
    $capture = $sync->begin($state->id);
    $sync->handle($state->id, $capture['revision'], $account, $page);

    expect(AnalyticsPublication::query()->where('social_account_key', $account->id)->count())->toBe(2);
});

test('backfill records truthful terminal coverage and initializes discovery high water', function (PublicationPage $page, SyncStatus $expectedStatus, ?string $reason) {
    Bus::fake();
    $account = SocialAccount::factory()->mastodon()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage($page);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe($expectedStatus)
        ->and($state->fresh()->last_error_category)->toBe($reason);
    $discovery = AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->where('collector', SyncCollector::PublicationDiscovery)->firstOrFail();
    expect($discovery->high_watermark_at)->not->toBeNull();
    Bus::assertNotDispatched(BackfillAccountPublications::class);
})->with([
    'exhausted' => [new PublicationPage([], null, true), SyncStatus::Complete, null],
    'provider limited' => [new PublicationPage([], null, true, true), SyncStatus::ProviderLimited, 'provider_limited'],
    'partial permission' => [new PublicationPage([], null, true, false, 'mastodon_reconnect_for_private_history'), SyncStatus::Partial, 'mastodon_reconnect_for_private_history'],
]);

test('reaching the 365 day target stops pagination even when the provider has another cursor', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $target = CarbonImmutable::parse('2025-09-23', 'UTC');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'older', 'revision' => 0],
        'target_since' => $target,
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('boundary-post', $target, PublicationContentType::Image),
    ], 'even-older', false));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(data_get($state->fresh()->checkpoint, 'cursor'))->toBeNull();
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('a limited page still advances to older pages and preserves its coverage warning', function () {
    $account = SocialAccount::factory()->mastodon()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);
    $first = $sync->begin($state->id);

    $result = $sync->handle($state->id, $first['revision'], $account, new PublicationPage([], 'older', false, true));

    expect($result)->toBe(['advanced' => true, 'terminal' => false])
        ->and($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => 'older', 'had_provider_limit' => true]);

    $second = $sync->begin($state->id);
    expect($second['cursor'])->toBe('older');
    $sync->handle($state->id, $second['revision'], $account, new PublicationPage([
        new DiscoveredPublication('older-post', CarbonImmutable::parse('2026-01-01', 'UTC'), PublicationContentType::Image),
    ], null, true));

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and(AnalyticsPublication::query()->where('remote_id', 'older-post')->exists())->toBeTrue();
});

test('an unordered provider keeps paging even when a publication lands on the cutoff', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::Pinterest]);
    $target = CarbonImmutable::parse('2025-09-23', 'UTC');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => $target,
    ]);
    bindPublicationPage(new PublicationPage(
        [new DiscoveredPublication('boundary-pin', $target, PublicationContentType::Image)],
        'pin-next',
        false,
        canStopAtTarget: false,
    ));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and(data_get($state->fresh()->checkpoint, 'cursor'))->toBe('pin-next');
    Bus::assertDispatched(BackfillAccountPublications::class);
});

test('a stale page can reconcile facts but cannot move the current cursor backwards', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'page-a', 'revision' => 0, 'seen_count' => 100],
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);
    $first = $sync->begin($state->id);
    $second = $sync->begin($state->id);

    $result = $sync->handle($state->id, $first['revision'], $account, new PublicationPage([
        new DiscoveredPublication(
            'stale-fact',
            CarbonImmutable::parse('2026-09-01', 'UTC'),
            PublicationContentType::Text,
        ),
    ], 'stale-next', false));

    expect($result['advanced'])->toBeFalse()
        ->and($state->fresh()->checkpoint)->toMatchArray([
            'cursor' => 'page-a',
            'revision' => $second['revision'],
            'seen_count' => 100,
        ])
        ->and(AnalyticsPublication::query()->where('remote_id', 'stale-fact')->exists())->toBeTrue();
});

function bindFailingPublicationPage(SocialAccount $account, Throwable $failure): void
{
    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor): bool => $received->is($account) && $cursor === 'resume-here',
    )->andThrow($failure);
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
}

test('a transient failure dispatches a delayed retry and preserves the cursor', function (Throwable $failure) {
    Bus::fake();
    Exceptions::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, $failure);

    expect($state->fresh()->status)->toBe(SyncStatus::Pending);

    $job = (new BackfillAccountPublications($account->id, $state->id))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->syncStateId === $state->id
        && $retry->transientRetries === 1
        && $retry->delay === 300);
    Exceptions::assertNothingReported();
    expect(data_get($state->fresh()->checkpoint, 'cursor'))->toBe('resume-here')
        ->and($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->last_error_category)->toBe('transient');
})->with([
    'provider 5xx' => fn () => new AnalyticsCollectionException('transient', 'publication history collection failed with HTTP 503'),
    'connection reset' => fn () => new ConnectionException('cURL error 56: Recv failure: Connection reset by peer'),
]);

test('a rate limited failure waits for the provider retry time when it is later than the backoff', function () {
    Bus::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('rate_limited', 'slow down', CarbonImmutable::now()->addMinutes(20)));

    Log::spy();

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->delay === 1200);
    expect($state->fresh()->last_error_category)->toBe('rate_limited');
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'analytics.collection'
        && data_get($context, 'category') === 'rate_limited'
        && data_get($context, 'retry_at') === CarbonImmutable::now('UTC')->addSeconds(1200)->toIso8601String())->once();
});

test('a provider retry time beyond the end of the day is capped at the end of the day', function () {
    Bus::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('rate_limited', 'slow down', CarbonImmutable::now('UTC')->addDays(30)));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->delay === 43200);
});

test('a provider retry time later than the backoff but within the day is honoured', function () {
    Bus::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'));
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('rate_limited', 'slow down', CarbonImmutable::now('UTC')->addHours(6)));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->delay === 21600);
});

test('a job queued before the retry counter existed still retries a transient failure', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('transient', 'temporary'));

    $queuedBeforeDeploy = (new ReflectionClass(BackfillAccountPublications::class))->newInstanceWithoutConstructor();
    $queuedBeforeDeploy->socialAccountId = $account->id;
    $queuedBeforeDeploy->syncStateId = $state->id;

    app()->call([$queuedBeforeDeploy, 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->transientRetries === 1);
});

test('the transient backoff follows our own retry counter', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('transient', 'temporary'));

    app()->call([new BackfillAccountPublications($account->id, $state->id, transientRetries: 2), 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->transientRetries === 3
        && $retry->delay === 7200);
});

test('middleware releases do not consume the transient retry cap', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('transient', 'temporary'));

    $job = (new BackfillAccountPublications($account->id, $state->id))->withFakeQueueInteractions();
    $job->job->attempts = 9;
    app()->call([$job, 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->transientRetries === 1
        && $retry->delay === 300);
    expect($state->fresh()->status)->toBe(SyncStatus::Running);
});

function maxTransientAnalyticsRetries(): int
{
    return (new ReflectionClassConstant(AbstractPublicationSync::class, 'MAX_TRANSIENT_RETRIES'))->getValue();
}

test('the last transient retry below the cap is still dispatched', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('transient', 'temporary'));

    app()->call([new BackfillAccountPublications($account->id, $state->id, transientRetries: maxTransientAnalyticsRetries() - 1), 'handle']);

    Bus::assertDispatched(BackfillAccountPublications::class, fn (BackfillAccountPublications $retry): bool => $retry->transientRetries === maxTransientAnalyticsRetries());
    expect($state->fresh()->status)->toBe(SyncStatus::Running);
});

test('a transient failure at the retry cap fails the sync so the scheduler restarts it', function () {
    Bus::fake();
    Exceptions::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    bindFailingPublicationPage($account, new AnalyticsCollectionException('transient', 'temporary'));

    $job = (new BackfillAccountPublications($account->id, $state->id, transientRetries: maxTransientAnalyticsRetries()))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Exceptions::assertNothingReported();
    expect(data_get($state->fresh()->checkpoint, 'cursor'))->toBe('resume-here')
        ->and($state->fresh()->status)->toBe(SyncStatus::Failed)
        ->and($state->fresh()->last_error_category)->toBe('queue_failed');

    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn (BootstrapAccountAnalytics $job): bool => $job->socialAccountId === $account->id);
});

test('an invalid provider cursor clears only the cursor and restarts the bounded backfill', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $target = CarbonImmutable::parse('2025-09-23', 'UTC');
    $oldest = CarbonImmutable::parse('2026-01-10', 'UTC');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'expired-cursor', 'revision' => 4, 'seen_count' => 100],
        'target_since' => $target,
        'oldest_reached_at' => $oldest,
    ]);
    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()
        ->andThrow(new AnalyticsCollectionException('invalid_cursor', 'expired'));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Pending)
        ->and($state->fresh()->target_since?->equalTo($target))->toBeTrue()
        ->and($state->fresh()->oldest_reached_at?->equalTo($oldest))->toBeTrue()
        ->and(data_get($state->fresh()->checkpoint, 'cursor'))->toBeNull()
        ->and(data_get($state->fresh()->checkpoint, 'seen_count'))->toBe(0);
    Bus::assertDispatched(BackfillAccountPublications::class);
});

test('a second invalid cursor stops the same backfill instead of looping forever', function () {
    $account = SocialAccount::factory()->x()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'bad-page', 'revision' => 0, 'invalid_cursor_resets' => 1],
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);
    $capture = $sync->begin($state->id);

    expect($sync->resetInvalidCursor($state->id, $capture['revision'], $account->id))->toBeFalse()
        ->and($state->fresh()->status)->toBe(SyncStatus::Partial)
        ->and($state->fresh()->last_error_category)->toBe('invalid_cursor_repeated');
});

test('an expired cursor after reconnect preserves imported history instead of rereading it', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'checkpoint' => ['cursor' => 'expired-after-disconnect', 'revision' => 4, 'seen_count' => 100],
        'oldest_reached_at' => CarbonImmutable::parse('2026-08-01', 'UTC'),
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20', 'UTC'),
    ]);
    $account->delete();
    $replacement = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => $account->platform_user_id,
    ]);
    (new BootstrapAccountAnalytics($replacement->id))->handleFor($replacement->id);
    Bus::fake();

    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()
        ->andThrow(new AnalyticsCollectionException('invalid_cursor', 'expired'));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);

    app()->call([new BackfillAccountPublications($replacement->id, $state->id), 'handle']);

    $discovery = AnalyticsSyncState::query()->where('social_account_id', $replacement->id)
        ->forCollector(SyncCollector::PublicationDiscovery)->firstOrFail();

    expect($state->fresh()->status)->toBe(SyncStatus::Partial)
        ->and($state->fresh()->last_error_category)->toBe('reconnect_cursor_expired')
        ->and($state->fresh()->oldest_reached_at?->toDateString())->toBe('2026-08-01')
        ->and($discovery->high_watermark_at?->toDateString())->toBe('2026-09-20');
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class, fn ($job): bool => $job->syncStateId === $discovery->id);
});

test('daily discovery is suppressed during backfill and resumes after terminal state', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Running,
    ]);
    $discovery = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Pending,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    $job = new DiscoverAccountPublications($account->id, $discovery->id);
    app()->call([$job, 'handle']);
    expect($discovery->fresh()->status)->toBe(SyncStatus::Pending);

    AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->where('collector', SyncCollector::PublicationBackfill)
        ->update(['status' => SyncStatus::Complete]);
    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor, CarbonImmutable $cutoff): bool => $received->is($account)
            && $cursor === null
            && $cutoff->equalTo(CarbonImmutable::parse('2026-09-20 06:00:00', 'UTC')),
    )->andReturn(new PublicationPage([], null, true));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
    app()->call([$job, 'handle']);

    expect($discovery->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($discovery->fresh()->last_success_at)->not->toBeNull();
});

test('deleting an account detaches its sync state and retains publication history', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create(['social_account_id' => $account->id]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
    ]);

    $account->delete();

    expect($state->fresh())->not->toBeNull()
        ->and($state->fresh()->social_account_id)->toBeNull()
        ->and($publication->fresh())->not->toBeNull()
        ->and($publication->fresh()->social_account_id)->toBeNull();
});

test('reconnecting the same identity reuses completed history and discovers only newer posts', function (Platform $platform, Platform $reconnectedPlatform) {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => $platform]);
    $backfill = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);
    $discovery = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);
    $oldPublication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $platform,
        'remote_id' => 'before-disconnect',
    ]);

    $account->delete();
    $replacement = SocialAccount::factory()->create([
        'workspace_id' => $account->workspace_id,
        'platform' => $reconnectedPlatform,
        'platform_user_id' => $account->platform_user_id,
    ]);

    (new BootstrapAccountAnalytics($replacement->id))->handleFor($replacement->id);

    expect($backfill->fresh()->social_account_id)->toBe($replacement->id)
        ->and($backfill->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($discovery->fresh()->social_account_id)->toBe($replacement->id)
        ->and($oldPublication->fresh()->social_account_id)->toBe(
            $platform === $reconnectedPlatform ? $replacement->id : null,
        );
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class, fn ($job): bool => $job->socialAccountId === $replacement->id && $job->syncStateId === $discovery->id);

    $collector = Mockery::mock(AbstractPublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor, CarbonImmutable $cutoff): bool => $received->is($replacement)
            && $cursor === null
            && $cutoff->equalTo(CarbonImmutable::parse('2026-09-20 06:00:00', 'UTC')),
    )->andReturn(new PublicationPage([
        new DiscoveredPublication('after-reconnect', CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'), PublicationContentType::Text),
    ], null, true));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
    app()->call([new DiscoverAccountPublications($replacement->id, $discovery->id), 'handle']);

    expect(AnalyticsPublication::query()->where('workspace_id', $replacement->workspace_id)->count())->toBe(2)
        ->and(AnalyticsPublication::query()->where('remote_id', 'after-reconnect')->value('social_account_key'))->toBe($account->id);
})->with([
    'x' => [Platform::X, Platform::X],
    'instagram' => [Platform::Instagram, Platform::Instagram],
    'instagram via facebook' => [Platform::Instagram, Platform::InstagramFacebook],
]);

test('reconnecting during backfill resumes its cursor instead of starting from the first page', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'checkpoint' => ['cursor' => 'next-page', 'revision' => 4, 'seen_count' => 200],
    ]);

    $account->delete();
    $replacement = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => $account->platform_user_id,
    ]);

    (new BootstrapAccountAnalytics($replacement->id))->handleFor($replacement->id);

    expect($state->fresh()->social_account_id)->toBe($replacement->id)
        ->and($state->fresh()->status)->toBe(SyncStatus::Pending)
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => 'next-page', 'revision' => 5, 'seen_count' => 200]);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->socialAccountId === $replacement->id && $job->syncStateId === $state->id);
    expect(app(AdvanceAnalyticsSyncState::class)->begin($state->id, socialAccountId: $account->id))->toBeNull();
});

test('a page fetched by the disconnected account cannot advance the rebound checkpoint', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Running,
        'checkpoint' => ['cursor' => 'next-page', 'revision' => 4, 'seen_count' => 100],
    ]);
    $account->delete();
    $replacement = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => $account->platform_user_id,
    ]);
    (new BootstrapAccountAnalytics($replacement->id))->handleFor($replacement->id);

    $result = app(AdvanceAnalyticsSyncState::class)->handle(
        $state->id,
        4,
        $account,
        new PublicationPage([
            new DiscoveredPublication('stale-post', CarbonImmutable::parse('2026-09-01', 'UTC'), PublicationContentType::Text),
        ], null, true),
    );

    expect($result)->toBe(['advanced' => false, 'terminal' => true])
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => 'next-page', 'revision' => 5, 'seen_count' => 100])
        ->and(AnalyticsPublication::query()->where('remote_id', 'stale-post')->exists())->toBeFalse();
});

test('a different account identity cannot inherit another accounts checkpoint', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Complete,
    ]);
    $account->delete();
    $other = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => 'different-x-user',
    ]);

    (new BootstrapAccountAnalytics($other->id))->handleFor($other->id);

    $otherBackfill = AnalyticsSyncState::query()
        ->where('social_account_id', $other->id)
        ->forCollector(SyncCollector::PublicationBackfill)
        ->firstOrFail();

    expect($state->fresh()->social_account_id)->toBeNull()
        ->and($otherBackfill->id)->not->toBe($state->id)
        ->and($otherBackfill->status)->toBe(SyncStatus::Pending);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->socialAccountId === $other->id);
});

test('history without a surviving checkpoint is marked partial and only new posts are discovered', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => null,
        'social_account_key' => fake()->uuid(),
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'provider_published_at' => CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC'),
    ]);

    (new BootstrapAccountAnalytics($account->id))->handleFor($account->id);

    $backfill = AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->forCollector(SyncCollector::PublicationBackfill)->firstOrFail();
    $discovery = AnalyticsSyncState::query()->where('social_account_id', $account->id)
        ->forCollector(SyncCollector::PublicationDiscovery)->firstOrFail();

    expect($backfill->status)->toBe(SyncStatus::Partial)
        ->and($backfill->last_error_category)->toBe('prior_checkpoint_unavailable')
        ->and($discovery->high_watermark_at?->toDateString())->toBe('2026-09-10');
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class, fn ($job): bool => $job->syncStateId === $discovery->id);

    (new BootstrapAccountAnalytics($account->id))->handleFor($account->id);

    expect($backfill->fresh()->status)->toBe(SyncStatus::Partial);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('a backfill page imports its publications as network posts', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('native-1', CarbonImmutable::now('UTC')->subDay(), PublicationContentType::Image, excerpt: 'From the app'),
    ], null, true));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    $post = Post::query()->imported()->sole();

    expect($post->content)->toBe('From the app')
        ->and($post->platform_post_id)->toBe('native-1');
});

test('discovery imports a post published since the last run', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::Complete,
    ]);
    $discovery = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::now('UTC')->subHours(3),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('fresh-1', CarbonImmutable::now('UTC')->subHour(), PublicationContentType::Image),
    ], null, true));

    app()->call([new DiscoverAccountPublications($account->id, $discovery->id), 'handle']);

    expect(Post::query()->imported()->sole()->platform_post_id)->toBe('fresh-1');
});

test('a page dispatches one media import per imported post', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('native-1', CarbonImmutable::now('UTC')->subDay(), PublicationContentType::Image),
    ], null, true));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    $post = Post::query()->imported()->sole();
    Bus::assertDispatched(ImportExternalPostMedia::class, fn (ImportExternalPostMedia $job): bool => $job->postId === $post->id);
});

test('a failing import is reported and the page still queues its metrics', function () {
    Bus::fake();
    Exceptions::fake();
    config()->set('trypost.posts.history_retention_days', 'broken');
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => null, 'revision' => 0],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('native-1', CarbonImmutable::now('UTC')->subDay(), PublicationContentType::Image),
    ], 'next-page', false));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect(Post::query()->imported()->exists())->toBeFalse();
    Exceptions::assertReported(InvalidArgumentException::class);
    Bus::assertDispatched(CollectPublicationMetrics::class);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->syncStateId === $state->id);
});

test('discovery reads back the configured overlap from the high watermark', function () {
    config()->set('trypost.analytics.discovery_overlap_hours', 1);
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    $capture = app(AdvanceAnalyticsSyncState::class)->begin($state->id, restartTerminal: true);

    expect($capture['cutoff']->toIso8601String())->toBe('2026-09-20T11:00:00+00:00');
});

test('a failed discovery run keeps the watermark so the next run starts from the same point', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Pending,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);

    $first = $sync->begin($state->id);
    $sync->recordFailure($state->id, $first['revision'], 'transient', false, $account->id);
    $second = $sync->begin($state->id);

    expect($state->fresh()->high_watermark_at->toIso8601String())->toBe('2026-09-20T12:00:00+00:00')
        ->and($second['cutoff']->equalTo($first['cutoff']))->toBeTrue();
});

test('a discovery run failing on a later page keeps the previous watermark', function () {
    config()->set('trypost.analytics.discovery_overlap_hours', 6);
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);

    $first = $sync->begin($state->id, restartTerminal: true, socialAccountId: $account->id);
    $sync->handle($state->id, $first['revision'], $account, new PublicationPage([
        new DiscoveredPublication('newest', CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC'), PublicationContentType::Image),
    ], 'page-2', false));
    $second = $sync->begin($state->id, socialAccountId: $account->id);
    $sync->recordFailure($state->id, $second['revision'], 'permission_denied', true, $account->id);
    $next = $sync->begin($state->id, restartTerminal: true, socialAccountId: $account->id);

    expect($second['cursor'])->toBe('page-2')
        ->and($second['cutoff']->toIso8601String())->toBe('2026-09-20T06:00:00+00:00')
        ->and($state->fresh()->high_watermark_at->toIso8601String())->toBe('2026-09-20T12:00:00+00:00')
        ->and($next['cursor'])->toBeNull()
        ->and($next['cutoff']->toIso8601String())->toBe('2026-09-20T06:00:00+00:00');
});

test('a discovery run advances the watermark once it reaches its last page', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);
    $sync = app(AdvanceAnalyticsSyncState::class);

    $first = $sync->begin($state->id, restartTerminal: true, socialAccountId: $account->id);
    $sync->handle($state->id, $first['revision'], $account, new PublicationPage([
        new DiscoveredPublication('newest', CarbonImmutable::parse('2026-09-21 10:00:00', 'UTC'), PublicationContentType::Image),
    ], 'page-2', false));
    $second = $sync->begin($state->id, socialAccountId: $account->id);
    $result = $sync->handle($state->id, $second['revision'], $account, new PublicationPage([
        new DiscoveredPublication('older', CarbonImmutable::parse('2026-09-20 13:00:00', 'UTC'), PublicationContentType::Image),
    ], null, true));

    expect($result['terminal'])->toBeTrue()
        ->and($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($state->fresh()->high_watermark_at->toIso8601String())->toBe('2026-09-21T10:00:00+00:00')
        ->and($state->fresh()->checkpoint)->not->toHaveKey('run_high_watermark_at');
});

test('the discovery command can run only x or everything but x', function () {
    $x = SocialAccount::factory()->x()->create();
    $instagram = SocialAccount::factory()->instagram()->create();

    foreach ([$x, $instagram] as $account) {
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => SyncStatus::Complete,
        ]);
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationDiscovery,
        ]);
    }

    Bus::fake();
    $this->artisan('analytics:dispatch-publication-discovery', ['--platform' => ['x']])->assertSuccessful();
    expect(Bus::dispatched(DiscoverAccountPublications::class)->pluck('socialAccountId')->all())->toBe([$x->id]);

    Bus::fake();
    $this->artisan('analytics:dispatch-publication-discovery', ['--except-platform' => ['x']])->assertSuccessful();
    expect(Bus::dispatched(DiscoverAccountPublications::class)->pluck('socialAccountId')->all())->toBe([$instagram->id]);
});

test('a reconnect resumes a legacy provider-limited backfill only when it restores the history grant', function (array $oldScopes, array $newScopes, bool $resumes) {
    Bus::fake();
    $account = SocialAccount::factory()->tiktok()->create(['scopes' => $oldScopes]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
    ]);

    $account->update(['scopes' => $newScopes]);
    (new BootstrapAccountAnalytics($account->id, refreshOnTerminal: true))->handle();

    expect($state->fresh()->status)->toBe($resumes ? SyncStatus::Pending : SyncStatus::ProviderLimited);
    Bus::assertDispatchedTimes(BackfillAccountPublications::class, $resumes ? 1 : 0);
})->with([
    'history restored' => [['video.publish'], ['video.publish', 'video.list'], true],
    'only an unrelated permission added' => [['video.list'], ['video.list', 'video.publish'], false],
    'reported grant cleared' => [['video.publish'], [], false],
]);

test('a restored X history grant restarts its timeline count and keeps the checkpoint revision', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create(['scopes' => ['tweet.write']]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationBackfill,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
        'checkpoint' => ['cursor' => null, 'revision' => 3, 'seen_count' => 243],
    ]);

    $account->update(['scopes' => ['tweet.write', 'tweet.read', 'users.read']]);

    expect($state->fresh()->checkpoint)->toMatchArray(['seen_count' => 0, 'revision' => 3]);
});

test('restoring private history during a Mastodon backfill recovers earlier private posts', function (bool $legacyRateLimit) {
    Bus::fake();
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['read:accounts', 'write:statuses']]);
    $state = AnalyticsSyncState::factory()->create([...AnalyticsSyncState::identityFor($account), 'social_account_id' => $account->id]);
    $endpoint = $account->mastodon_instance.'/api/v1/accounts/'.$account->platform_user_id.'/statuses';
    $status = fn (string $id, int $days): array => ['id' => $id, 'created_at' => now()->subDays($days)->toIso8601String()];
    Http::preventStrayRequests();
    Http::fake([$endpoint.'*' => function (Request $request) use ($endpoint, $status) {
        if (filled(data_get($request->data(), 'max_id'))) {
            return Http::response([$status('100', 60)]);
        }

        $statuses = [$status('400', 1)];
        if ($request->hasHeader('Authorization')) {
            $statuses[] = [...$status('300', 25), 'visibility' => 'private'];
        }
        $statuses[] = $status('200', 50);

        return Http::response($statuses, 200, ['Link' => '<'.$endpoint.'?max_id=200>; rel="next"']);
    }]);

    $job = new BackfillAccountPublications($account->id, $state->id);
    app()->call([$job, 'handle']);
    expect($state->fresh()->status)->toBe(SyncStatus::Running);

    if ($legacyRateLimit) {
        AnalyticsPublication::whereBelongsTo($account, 'socialAccount')->update(['provider_metadata' => null]);
        $state->update([
            'checkpoint' => ['cursor' => '200', 'revision' => 2],
            'last_error_category' => 'rate_limited',
        ]);
    }

    $account->update(['scopes' => ['read:accounts', 'read:statuses', 'write:statuses']]);
    (new BootstrapAccountAnalytics($account->id, refreshOnTerminal: true))->handle();
    for ($page = 0; $page < 3 && ! $state->fresh()->isTerminal(); $page++) {
        app()->call([$job, 'handle']);
    }

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(AnalyticsPublication::whereBelongsTo($account, 'socialAccount')->pluck('remote_id')->all())
        ->toEqualCanonicalizing(['100', '200', '300', '400']);
})->with(['current checkpoint' => false, 'rate-limited checkpoint before upgrade' => true]);

test('reconnecting a deleted channel retries a legacy scope refusal and preserves a known provider limit', function (string $platform, array $oldScopes, bool $retries) {
    Bus::fake();
    $old = SocialAccount::factory()->{$platform}()->create(['scopes' => $oldScopes]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($old),
        'social_account_id' => $old->id,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
    ]);
    $old->delete();
    $account = SocialAccount::factory()->{$platform}()->create(['workspace_id' => $old->workspace_id, 'platform_user_id' => $old->platform_user_id]);

    (new BootstrapAccountAnalytics($account->id))->handle();

    expect($state->fresh()->social_account_id)->toBe($account->id);
    Bus::assertDispatchedTimes(BackfillAccountPublications::class, $retries ? 1 : 0);
    Bus::assertDispatchedTimes(DiscoverAccountPublications::class, $retries ? 0 : 1);
})->with([
    'TikTok history was denied' => ['tiktok', ['video.publish'], true],
    'TikTok history was granted' => ['tiktok', ['video.list'], false],
    'Pinterest history was granted' => ['pinterest', ['boards:read', 'pins:read'], false],
]);

test('a legacy orphaned TikTok limit without a saved grant is retried only for an empty history', function (bool $imported) {
    Bus::fake();
    $account = SocialAccount::factory()->tiktok()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => null,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
        'oldest_reached_at' => $imported ? now()->subMonth() : null,
    ]);

    (new BootstrapAccountAnalytics($account->id))->handle();

    expect($state->fresh()->social_account_id)->toBe($account->id);
    Bus::assertDispatchedTimes(BackfillAccountPublications::class, $imported ? 0 : 1);
    Bus::assertDispatchedTimes(DiscoverAccountPublications::class, $imported ? 1 : 0);
})->with(['empty history' => false, 'previously imported history' => true]);

test('restoring Mastodon private discovery imports a status older than the newest public post', function (?string $legacyStatus) {
    Bus::fake();
    $this->freezeTime();
    $account = SocialAccount::factory()->mastodon()->create();
    $originalScopes = $account->scopes;
    $safeWatermark = now()->subDays(5);
    $publicPost = ['id' => '400', 'created_at' => now()->subDay()->toIso8601String(), 'visibility' => 'public'];
    $privatePost = ['id' => '300', 'created_at' => now()->subDays(3)->toIso8601String(), 'visibility' => 'private'];
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => $safeWatermark,
    ]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'status' => SyncStatus::Complete,
        'high_watermark_at' => $safeWatermark,
    ]);
    $endpoint = $account->mastodon_instance.'/api/v1/accounts/'.$account->platform_user_id.'/statuses';
    Http::preventStrayRequests();
    Http::fake([$endpoint.'*' => function (Request $request) use ($endpoint, $publicPost, $privatePost) {
        if (! $request->hasHeader('Authorization')) {
            return Http::response([$publicPost]);
        }

        if (data_get($request->data(), 'max_id') === '400') {
            return Http::response([$privatePost]);
        }

        return Http::response([$publicPost], 200, ['Link' => '<'.$endpoint.'?max_id=400>; rel="next"']);
    }]);

    $account->update(['scopes' => ['read:accounts', 'write:statuses']]);
    $job = new DiscoverAccountPublications($account->id, $state->id);
    app()->call([$job, 'handle']);
    expect($state->fresh()->status)->toBe(SyncStatus::Partial)
        ->and(Post::query()->imported()->pluck('platform_post_id')->all())->toBe(['400']);

    if ($legacyStatus === 'partial') {
        $state->update(['checkpoint' => ['cursor' => null, 'revision' => 1], 'high_watermark_at' => $publicPost['created_at']]);
    }

    if ($legacyStatus === 'rate_limited') {
        $state->update([
            'checkpoint' => ['cursor' => '250', 'revision' => 2, 'run_high_watermark_at' => $publicPost['created_at']],
            'high_watermark_at' => $publicPost['created_at'],
            'status' => SyncStatus::Running,
            'last_error_category' => 'rate_limited',
        ]);
    }

    if ($legacyStatus !== 'rate_limited') {
        app()->call([$job, 'handle']);
    }
    $account->update(['scopes' => $originalScopes]);
    (new BootstrapAccountAnalytics($account->id, refreshOnTerminal: true))->handle();
    app()->call([Bus::dispatched(DiscoverAccountPublications::class)->sole(), 'handle']);
    expect($state->fresh()->status)->toBe(SyncStatus::Running);
    app()->call([Bus::dispatched(DiscoverAccountPublications::class)->last(), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and($state->fresh()->high_watermark_at->equalTo($publicPost['created_at']))->toBeTrue()
        ->and(Post::query()->imported()->pluck('platform_post_id')->all())->toEqualCanonicalizing(['400', '300']);
})->with([
    'public discovery' => [null],
    'completed public run before upgrade' => ['partial'],
    'rate-limited public page before upgrade' => ['rate_limited'],
]);

test('a reconnected Pinterest channel imports older pins after a legacy history refusal', function (bool $deletedBeforeUpgrade) {
    Bus::fake();
    $this->freezeTime();
    $old = SocialAccount::factory()->pinterest()->create(['scopes' => ['pins:write', 'boards:read']]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($old),
        'social_account_id' => $old->id,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
        'oldest_reached_at' => $deletedBeforeUpgrade ? null : now()->subDays(2),
    ]);
    AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($old),
        'social_account_id' => $old->id,
        'collector' => SyncCollector::PublicationDiscovery,
        'high_watermark_at' => now()->subDay(),
    ]);
    $old->delete();
    if ($deletedBeforeUpgrade) {
        $state->update(['checkpoint' => ['cursor' => null, 'revision' => 1]]);
    }
    $account = SocialAccount::factory()->pinterest()->create(['workspace_id' => $old->workspace_id, 'platform_user_id' => $old->platform_user_id]);
    Http::preventStrayRequests();
    Http::fake([config('trypost.platforms.pinterest.api').'/pins?*' => Http::response(['items' => [
        ['id' => '123', 'created_at' => now()->subDays(7)->toIso8601String(), 'media' => ['media_type' => 'image']],
    ]])]);

    (new BootstrapAccountAnalytics($account->id))->handle();
    app()->call([Bus::dispatched(BackfillAccountPublications::class)->sole(), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(Post::query()->imported()->sole()->platform_post_id)->toBe('123');
})->with(['grant saved at disconnect' => false, 'deleted before upgrade' => true]);

test('Pinterest keeps a confirmed provider limit after losing its history scope and reconnecting', function (bool $recreate) {
    Bus::fake();
    $account = SocialAccount::factory()->pinterest()->create();
    $scopes = $account->scopes;
    $state = AnalyticsSyncState::factory()->create([...AnalyticsSyncState::identityFor($account), 'social_account_id' => $account->id]);
    Http::preventStrayRequests();
    Http::fake([config('trypost.platforms.pinterest.api').'/pins?*' => Http::response(['items' => [['id' => 'undated']]])]);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);
    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited);

    $account->update(['scopes' => ['pins:write']]);
    if ($recreate) {
        $account->delete();
        $account = SocialAccount::factory()->pinterest()->create(['workspace_id' => $account->workspace_id, 'platform_user_id' => $account->platform_user_id]);
    } else {
        $account->update(['scopes' => $scopes]);
    }

    (new BootstrapAccountAnalytics($account->id, refreshOnTerminal: true))->handle();

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class);
})->with(['same channel' => false, 'deleted and reconnected' => true]);

test('a reconnected X channel resets the timeline count only for a legacy history refusal', function (bool $missingGrant) {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create();
    $checkpoint = ['cursor' => null, 'revision' => 1, 'seen_count' => 230];
    if ($missingGrant) {
        $checkpoint['missing_history_grant'] = true;
    }
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => null,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
        'checkpoint' => $checkpoint,
    ]);

    (new BootstrapAccountAnalytics($account->id))->handle();

    expect($state->fresh()->checkpoint['seen_count'])->toBe($missingGrant ? 0 : 230);
    Bus::assertDispatchedTimes(BackfillAccountPublications::class, $missingGrant ? 1 : 0);
})->with(['known history refusal' => true, 'timeline rows already read' => false]);

test('a legacy Pinterest provider limit survives reconnecting without history and later granting it', function (bool $deleteAgain) {
    Bus::fake();
    $old = SocialAccount::factory()->pinterest()->create();
    $fullGrant = $old->scopes;
    $identity = ['workspace_id' => $old->workspace_id, 'platform_user_id' => $old->platform_user_id];
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($old),
        'social_account_id' => $old->id,
        'status' => SyncStatus::ProviderLimited,
        'last_error_category' => 'provider_limited',
        'checkpoint' => ['cursor' => null, 'revision' => 1],
    ]);

    $old->delete();
    $account = SocialAccount::factory()->pinterest()->create([...$identity, 'scopes' => ['pins:write', 'boards:read']]);
    (new BootstrapAccountAnalytics($account->id))->handle();

    if ($deleteAgain) {
        $account->delete();
        $account = SocialAccount::factory()->pinterest()->create($identity);
    } else {
        $account->update(['scopes' => $fullGrant]);
    }
    (new BootstrapAccountAnalytics($account->id, refreshOnTerminal: true))->handle();

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
    Bus::assertDispatched(DiscoverAccountPublications::class);
})->with(['reauthorized' => false, 'deleted and recreated again' => true]);

test('a Pinterest limit on an earlier page survives a failed page and bootstrap retry', function () {
    Bus::fake();
    $account = SocialAccount::factory()->pinterest()->create();
    $state = AnalyticsSyncState::factory()->create([...AnalyticsSyncState::identityFor($account), 'social_account_id' => $account->id]);
    $endpoint = config('trypost.platforms.pinterest.api').'/pins?*';
    Http::preventStrayRequests();
    Http::fake([$endpoint => Http::sequence()
        ->push(['items' => [['id' => 'undated']], 'bookmark' => 'page-two'])
        ->pushStatus(503)
        ->push(['items' => [['id' => '123', 'created_at' => now()->subDay()->toIso8601String()]]])]);

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);
    app()->call([new BackfillAccountPublications($account->id, $state->id, transientRetries: maxTransientAnalyticsRetries()), 'handle']);
    expect($state->fresh()->last_error_category)->toBe('queue_failed');

    (new BootstrapAccountAnalytics($account->id))->handle();
    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and(Post::query()->imported()->sole()->platform_post_id)->toBe('123');
});

test('retrying a partial Pinterest backfill starts at the first page and measures the provider limit again', function () {
    Bus::fake();
    $account = SocialAccount::factory()->pinterest()->create();
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Partial,
        'last_error_category' => 'invalid_cursor_repeated',
        'checkpoint' => ['cursor' => 'expired', 'revision' => 4, 'had_provider_limit' => true, 'invalid_cursor_resets' => 1],
    ]);
    Http::preventStrayRequests();
    Http::fake([config('trypost.platforms.pinterest.api').'/pins?*' => Http::response(['items' => [
        ['id' => '123', 'created_at' => now()->subDay()->toIso8601String()],
    ]])]);

    (new BootstrapAccountAnalytics($account->id))->handle();
    app()->call([Bus::dispatched(BackfillAccountPublications::class)->sole(), 'handle']);

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('bookmark', $request->data()));
    expect($state->fresh()->status)->toBe(SyncStatus::Complete)
        ->and(Post::query()->imported()->sole()->platform_post_id)->toBe('123');
});
