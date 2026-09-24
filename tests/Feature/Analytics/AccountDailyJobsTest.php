<?php

declare(strict_types=1);

use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\FinalizeAccountDailySnapshot;
use App\Jobs\Analytics\FinalizeAccountDailySnapshots;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Analytics\Collectors\Followers\AbstractFollowerCollector;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-23 02:00:00 UTC');
    Bus::fake();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

test('dispatcher queues every eligible account independently', function () {
    $workspace = Workspace::factory()->create();
    $first = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $second = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $inactive = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'is_active' => false]);
    $disconnected = SocialAccount::factory()->x()->disconnected()->create(['workspace_id' => $workspace->id]);

    Artisan::call('analytics:dispatch-account-daily');

    foreach ([$first, $second] as $account) {
        Bus::assertDispatched(CollectAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $account->id
            && $job->observationDate === '2026-09-23'
            && $job->queue === 'analytics');
    }

    foreach ([$linkedin, $inactive, $disconnected] as $account) {
        Bus::assertNotDispatched(CollectAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $account->id);
    }
});

test('finalizer dispatches one job per eligible account for both scheduled dates', function () {
    $first = SocialAccount::factory()->instagram()->create();
    $second = SocialAccount::factory()->x()->create();
    SocialAccount::factory()->linkedin()->create();
    SocialAccount::factory()->x()->create(['is_active' => false]);
    SocialAccount::factory()->x()->disconnected()->create();

    app()->call([new FinalizeAccountDailySnapshots, 'handle']);

    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, 2);
    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $first->id
        && $job->observationDate === '2026-09-23'
        && $job->queue === 'analytics');
    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $second->id
        && $job->observationDate === '2026-09-23');

    app()->call([new FinalizeAccountDailySnapshots(daysAgo: 1), 'handle']);

    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, 4);
    Bus::assertDispatched(FinalizeAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $first->id
        && $job->observationDate === '2026-09-22');
});

test('collection job writes once and skips an existing actual observation', function () {
    $account = SocialAccount::factory()->x()->create();
    $collector = Mockery::mock(AbstractFollowerCollector::class);
    $collector->shouldReceive('collect')->once()->andReturn(followerObservation(25));
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->twice()->with(Platform::X)->andReturnTrue();
    $factory->shouldReceive('for')->once()->with(Platform::X)->andReturn($collector);
    $job = new CollectAccountDailySnapshot($account->id, '2026-09-23');

    app()->call([$job, 'handle'], ['collectors' => $factory]);
    app()->call([$job, 'handle'], ['collectors' => $factory]);

    expect(AnalyticsAccountDailySnapshot::count())->toBe(1)
        ->and(AnalyticsAccountDailySnapshot::first()->followers_count)->toBe(25);
});

test('reconnecting the same identity keeps todays follower snapshot without another provider read', function () {
    $account = SocialAccount::factory()->x()->create();
    app(WriteAccountDailySnapshot::class)->handle($account, followerObservation(25));
    $account->delete();
    $replacement = SocialAccount::factory()->x()->create([
        'workspace_id' => $account->workspace_id,
        'platform_user_id' => $account->platform_user_id,
    ]);
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->once()->with(Platform::X)->andReturnTrue();
    $factory->shouldNotReceive('for');

    app()->call([new CollectAccountDailySnapshot($replacement->id, '2026-09-23'), 'handle'], ['collectors' => $factory]);

    expect(AnalyticsAccountDailySnapshot::query()->count())->toBe(1)
        ->and(AnalyticsAccountDailySnapshot::query()->firstOrFail()->social_account_key)->toBe($account->id);
});

test('collection job retries at spaced windows and honors a later provider retry time', function () {
    $account = SocialAccount::factory()->x()->create();
    $collector = Mockery::mock(AbstractFollowerCollector::class);
    $collector->shouldReceive('collect')->twice()
        ->andThrowExceptions([
            new AnalyticsCollectionException('transient', 'temporarily unavailable'),
            new AnalyticsCollectionException(
                'rate_limited',
                'rate limited',
                CarbonImmutable::parse('2026-09-23 07:30:00', 'UTC'),
            ),
        ]);
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->twice()->with(Platform::X)->andReturnTrue();
    $factory->shouldReceive('for')->twice()->with(Platform::X)->andReturn($collector);

    $windowJob = (new CollectAccountDailySnapshot($account->id, '2026-09-23'))
        ->withFakeQueueInteractions();
    app()->call([$windowJob, 'handle'], ['collectors' => $factory]);
    $windowJob->assertReleased(4 * 60 * 60);

    $providerJob = (new CollectAccountDailySnapshot($account->id, '2026-09-23'))
        ->withFakeQueueInteractions();
    app()->call([$providerJob, 'handle'], ['collectors' => $factory]);
    $providerJob->assertReleased((5 * 60 * 60) + (30 * 60));
});

test('connection failures use the same spaced retry window', function () {
    $account = SocialAccount::factory()->x()->create();
    $collector = Mockery::mock(AbstractFollowerCollector::class);
    $collector->shouldReceive('collect')->once()->andThrow(new ConnectionException('timed out'));
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->once()->with(Platform::X)->andReturnTrue();
    $factory->shouldReceive('for')->once()->with(Platform::X)->andReturn($collector);
    $job = (new CollectAccountDailySnapshot($account->id, '2026-09-23'))->withFakeQueueInteractions();

    app()->call([$job, 'handle'], ['collectors' => $factory]);

    $job->assertReleased(4 * 60 * 60);
});

test('collection job stops when provider retry time falls outside the observation day', function () {
    $account = SocialAccount::factory()->x()->create();
    $collector = Mockery::mock(AbstractFollowerCollector::class);
    $collector->shouldReceive('collect')->once()->andThrow(new AnalyticsCollectionException(
        'rate_limited',
        'rate limited',
        CarbonImmutable::parse('2026-09-24 01:00:00', 'UTC'),
    ));
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->once()->with(Platform::X)->andReturnTrue();
    $factory->shouldReceive('for')->once()->with(Platform::X)->andReturn($collector);
    $job = (new CollectAccountDailySnapshot($account->id, '2026-09-23'))
        ->withFakeQueueInteractions();

    app()->call([$job, 'handle'], ['collectors' => $factory]);

    $job->assertNotReleased();
});

test('analytics authorization failures do not disconnect an otherwise connected account', function (string $category) {
    $account = SocialAccount::factory()->instagram()->create();
    $collector = Mockery::mock(AbstractFollowerCollector::class);
    $collector->shouldReceive('collect')->once()->andThrow(new AnalyticsCollectionException(
        $category,
        'analytics endpoint rejected this request',
    ));
    $factory = Mockery::mock(FollowerCollectorFactory::class);
    $factory->shouldReceive('supports')->once()->with(Platform::Instagram)->andReturnTrue();
    $factory->shouldReceive('for')->once()->with(Platform::Instagram)->andReturn($collector);
    $job = (new CollectAccountDailySnapshot($account->id, '2026-09-23'))
        ->withFakeQueueInteractions();

    app()->call([$job, 'handle'], ['collectors' => $factory]);

    expect($account->fresh()->status)->toBe(Status::Connected)
        ->and(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $account->id)->exists())->toBeFalse();
    $job->assertNotReleased();
})->with(['authentication', 'permission']);

test('finalizer carries the latest measured total and does not invent missing history', function () {
    $workspace = Workspace::factory()->create();
    $withHistory = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $withoutHistory = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    app(WriteAccountDailySnapshot::class)->handle($withHistory, new AccountDailyObservation(
        date: CarbonImmutable::parse('2026-09-22', 'UTC'),
        followers: 50,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::parse('2026-09-22 02:00:00', 'UTC'),
    ));

    app()->call([new FinalizeAccountDailySnapshot($withHistory->id, '2026-09-23'), 'handle']);
    app()->call([new FinalizeAccountDailySnapshot($withoutHistory->id, '2026-09-23'), 'handle']);

    $carried = AnalyticsAccountDailySnapshot::query()->whereDate('date', '2026-09-23')->sole();
    expect($carried->social_account_id)->toBe($withHistory->id)
        ->and($carried->followers_count)->toBe(50)
        ->and($carried->provenance)->toBe(ObservationProvenance::CarriedForward)
        ->and($carried->provider_observed_at?->toDateTimeString())->toBe('2026-09-22 02:00:00')
        ->and(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $withoutHistory->id)->exists())->toBeFalse();
});

test('finalization worker skips accounts disconnected after dispatch', function () {
    $account = SocialAccount::factory()->x()->create();
    app(WriteAccountDailySnapshot::class)->handle($account, new AccountDailyObservation(
        date: CarbonImmutable::parse('2026-09-22', 'UTC'),
        followers: 50,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::parse('2026-09-22 02:00:00', 'UTC'),
    ));
    $job = new FinalizeAccountDailySnapshot($account->id, '2026-09-23');
    $account->delete();

    app()->call([$job, 'handle']);

    expect(AnalyticsAccountDailySnapshot::query()->whereDate('date', '2026-09-23')->exists())->toBeFalse();
});

test('next-day finalizer recovers a missed date without replacing actual observations', function () {
    $account = SocialAccount::factory()->x()->create();
    $writer = app(WriteAccountDailySnapshot::class);
    $writer->handle($account, new AccountDailyObservation(
        date: CarbonImmutable::parse('2026-09-21', 'UTC'),
        followers: 50,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::parse('2026-09-21 02:00:00', 'UTC'),
    ));
    $job = new FinalizeAccountDailySnapshot($account->id, '2026-09-22');

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    $recovered = AnalyticsAccountDailySnapshot::query()->whereDate('date', '2026-09-22')->sole();
    expect($recovered->followers_count)->toBe(50)
        ->and($recovered->provenance)->toBe(ObservationProvenance::CarriedForward)
        ->and($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([300, 900]);

    $writer->handle($account, new AccountDailyObservation(
        date: CarbonImmutable::parse('2026-09-22', 'UTC'),
        followers: 52,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::parse('2026-09-22 02:00:00', 'UTC'),
    ));
    app()->call([$job, 'handle']);

    expect($recovered->fresh()->followers_count)->toBe(52)
        ->and($recovered->fresh()->provenance)->toBe(ObservationProvenance::Actual);
});

function followerObservation(int $followers): AccountDailyObservation
{
    return new AccountDailyObservation(
        date: CarbonImmutable::parse('2026-09-23', 'UTC'),
        followers: $followers,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::now('UTC'),
    );
}
