<?php

declare(strict_types=1);

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]));

function bindPublicationPage(PublicationPage $page): void
{
    $collector = Mockery::mock(PublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->andReturn($page);

    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
}

test('publication jobs are provider limited and account overlap protected', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $backfill = new BackfillAccountPublications($account->id, fake()->uuid());
    $discovery = new DiscoverAccountPublications($account->id, fake()->uuid());

    expect($backfill->providerRateLimitKey())->toBe('instagram')
        ->and($backfill->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($backfill->middleware()[1])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($discovery->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($discovery->middleware()[1])->toBeInstanceOf(WithoutOverlapping::class);
});

test('discovery dispatcher loads sync states once for all accounts in a page', function () {
    $accounts = SocialAccount::factory()->instagram()->count(3)->create(['is_active' => true]);

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

test('bootstrap creates separate backfill and discovery states and dispatches the first page', function () {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-09-23 10:00:00 UTC');
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);

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

test('bootstrap resumes a failed backfill from its last committed cursor', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
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
    $account = SocialAccount::factory()->x()->create(['is_active' => true]);
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

test('x backfill reports provider limited when the 3200 post timeline ends before the target', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'last-page', 'revision' => 4, 'seen_count' => 3199],
        'target_since' => CarbonImmutable::parse('2025-09-23', 'UTC'),
    ]);
    bindPublicationPage(new PublicationPage([
        new DiscoveredPublication('x-3200', CarbonImmutable::parse('2026-01-01', 'UTC'), PublicationContentType::Text),
    ], null, true));

    app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']);

    expect($state->fresh()->status)->toBe(SyncStatus::ProviderLimited)
        ->and($state->fresh()->last_error_category)->toBe('x_timeline_3200')
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => null, 'seen_count' => 3200]);
    Bus::assertNotDispatched(BackfillAccountPublications::class);
});

test('x backfill below the timeline cap completes when the provider exhausts its history', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
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
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'status' => SyncStatus::ProviderLimited,
        'checkpoint' => ['cursor' => null, 'revision' => 4, 'seen_count' => 3200],
    ]);

    $started = app(AdvanceAnalyticsSyncState::class)->begin($state->id, restartTerminal: true);

    expect($started['cursor'])->toBeNull()
        ->and($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => null, 'revision' => 5, 'seen_count' => 0]);
});

test('a backfill job persists one page then advances its cursor and dispatches continuation', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
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

    expect(AnalyticsPublication::query()->where('provider_post_id', 'native-1')->exists())->toBeTrue()
        ->and($state->fresh()->checkpoint)->toMatchArray(['cursor' => 'next-page', 'revision' => 1])
        ->and($state->fresh()->status)->toBe(SyncStatus::Running);
    Bus::assertDispatched(BackfillAccountPublications::class, fn ($job): bool => $job->socialAccountId === $account->id && $job->syncStateId === $state->id);
});

test('a publication page resolves the account identity once for every post', function () {
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
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
    $account = SocialAccount::factory()->mastodon()->create(['is_active' => true]);
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
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
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

test('an unordered provider keeps paging even when a publication lands on the cutoff', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::Pinterest, 'is_active' => true]);
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
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
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
        ->and(AnalyticsPublication::query()->where('provider_post_id', 'stale-fact')->exists())->toBeTrue();
});

test('a transient failure preserves the cursor for a later queue attempt', function () {
    Bus::fake();
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'resume-here', 'revision' => 0],
    ]);
    $collector = Mockery::mock(PublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor): bool => $received->is($account) && $cursor === 'resume-here',
    )->andThrow(new AnalyticsCollectionException('transient', 'temporary'));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);

    expect(SocialAccount::query()->connected()->active()->includedInAnalytics()->find($account->id))
        ->not->toBeNull()
        ->and($state->fresh()->status)->toBe(SyncStatus::Pending);

    expect(fn () => app()->call([new BackfillAccountPublications($account->id, $state->id), 'handle']))
        ->toThrow(AnalyticsCollectionException::class);

    expect(data_get($state->fresh()->checkpoint, 'cursor'))->toBe('resume-here')
        ->and($state->fresh()->status)->toBe(SyncStatus::Running)
        ->and($state->fresh()->last_error_category)->toBe('transient');
});

test('an invalid provider cursor clears only the cursor and restarts the bounded backfill', function () {
    Bus::fake();
    $account = SocialAccount::factory()->create(['platform' => Platform::X, 'is_active' => true]);
    $target = CarbonImmutable::parse('2025-09-23', 'UTC');
    $oldest = CarbonImmutable::parse('2026-01-10', 'UTC');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'expired-cursor', 'revision' => 4, 'seen_count' => 100],
        'target_since' => $target,
        'oldest_reached_at' => $oldest,
    ]);
    $collector = Mockery::mock(PublicationHistoryCollector::class);
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

test('an expired cursor after reconnect preserves imported history instead of rereading it', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create(['is_active' => true]);
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
        'is_active' => true,
    ]);
    (new BootstrapAccountAnalytics($replacement->id))->handleFor($replacement->id);
    Bus::fake();

    $collector = Mockery::mock(PublicationHistoryCollector::class);
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
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
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
    $collector = Mockery::mock(PublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor, CarbonImmutable $cutoff): bool => $received->is($account)
            && $cursor === null
            && $cutoff->equalTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC')),
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
    $account = SocialAccount::factory()->create(['platform' => $platform, 'is_active' => true]);
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
        'provider_post_id' => 'before-disconnect',
    ]);

    $account->delete();
    $replacement = SocialAccount::factory()->create([
        'workspace_id' => $account->workspace_id,
        'platform' => $reconnectedPlatform,
        'platform_user_id' => $account->platform_user_id,
        'is_active' => true,
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

    $collector = Mockery::mock(PublicationHistoryCollector::class);
    $collector->shouldReceive('page')->once()->withArgs(
        fn (SocialAccount $received, ?string $cursor, CarbonImmutable $cutoff): bool => $received->is($replacement)
            && $cursor === null
            && $cutoff->equalTo(CarbonImmutable::parse('2026-09-17 12:00:00', 'UTC')),
    )->andReturn(new PublicationPage([
        new DiscoveredPublication('after-reconnect', CarbonImmutable::parse('2026-09-22 12:00:00', 'UTC'), PublicationContentType::Text),
    ], null, true));
    $factory = Mockery::mock(PublicationHistoryCollectorFactory::class);
    $factory->shouldReceive('for')->once()->andReturn($collector);
    app()->instance(PublicationHistoryCollectorFactory::class, $factory);
    app()->call([new DiscoverAccountPublications($replacement->id, $discovery->id), 'handle']);

    expect(AnalyticsPublication::query()->where('workspace_id', $replacement->workspace_id)->count())->toBe(2)
        ->and(AnalyticsPublication::query()->where('provider_post_id', 'after-reconnect')->value('social_account_key'))->toBe($account->id);
})->with([
    'x' => [Platform::X, Platform::X],
    'instagram' => [Platform::Instagram, Platform::Instagram],
    'instagram via facebook' => [Platform::Instagram, Platform::InstagramFacebook],
]);

test('reconnecting during backfill resumes its cursor instead of starting from the first page', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create(['is_active' => true]);
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
        'is_active' => true,
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
    $account = SocialAccount::factory()->x()->create(['is_active' => true]);
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
        'is_active' => true,
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
        ->and(AnalyticsPublication::query()->where('provider_post_id', 'stale-post')->exists())->toBeFalse();
});

test('a different account identity cannot inherit another accounts checkpoint', function () {
    Bus::fake();
    $account = SocialAccount::factory()->x()->create(['is_active' => true]);
    $state = AnalyticsSyncState::factory()->create([
        ...AnalyticsSyncState::identityFor($account),
        'social_account_id' => $account->id,
        'status' => SyncStatus::Complete,
    ]);
    $account->delete();
    $other = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => 'different-x-user',
        'is_active' => true,
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
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
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
