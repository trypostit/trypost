<?php

declare(strict_types=1);

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Contracts\Analytics\PublicationHistoryCollector;
use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\BackfillAccountPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;

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

test('a stale page can reconcile facts but cannot move the current cursor backwards', function () {
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'page-a', 'revision' => 0],
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
    $account = SocialAccount::factory()->instagram()->create(['is_active' => true]);
    $target = CarbonImmutable::parse('2025-09-23', 'UTC');
    $oldest = CarbonImmutable::parse('2026-01-10', 'UTC');
    $state = AnalyticsSyncState::factory()->create([
        'social_account_id' => $account->id,
        'checkpoint' => ['cursor' => 'expired-cursor', 'revision' => 4],
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
        ->and(data_get($state->fresh()->checkpoint, 'cursor'))->toBeNull();
    Bus::assertDispatched(BackfillAccountPublications::class);
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

test('deleting an account cascades operational states but retains publication history', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $state = AnalyticsSyncState::factory()->create(['social_account_id' => $account->id]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
    ]);

    $account->delete();

    expect(AnalyticsSyncState::query()->whereKey($state->id)->exists())->toBeFalse()
        ->and($publication->fresh())->not->toBeNull()
        ->and($publication->fresh()->social_account_id)->toBeNull();
});
