<?php

declare(strict_types=1);

use App\Actions\Analytics\WriteAccountDailySnapshot;
use App\Actions\Analytics\WritePublicationDailySnapshot;
use App\Dto\Analytics\AccountDailyObservation;
use App\Dto\Analytics\MetricValue;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

test('account observations are idempotent and preserve historical account identity', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'provider-account-1',
        'username' => 'shared-name',
    ]);
    $writer = app(WriteAccountDailySnapshot::class);

    $writer->handle($account, accountObservation('2026-09-22', 10));
    $writer->handle($account, accountObservation('2026-09-22', 0));
    $writer->handle($account, accountObservation('2026-09-23', null));

    expect(AnalyticsAccountDailySnapshot::count())->toBe(2)
        ->and(AnalyticsAccountDailySnapshot::query()
            ->whereDate('snapshot_date', '2026-09-22')
            ->value('followers_count'))->toBe(0)
        ->and(AnalyticsAccountDailySnapshot::query()
            ->whereDate('snapshot_date', '2026-09-23')
            ->value('followers_count'))->toBeNull();

    $historicalKey = $account->id;
    $account->deleteQuietly();

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_key', $historicalKey)->count())->toBe(2)
        ->and(AnalyticsAccountDailySnapshot::query()->where('social_account_key', $historicalKey)->whereNotNull('social_account_id')->exists())->toBeFalse();

    $reconnected = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'provider-account-1',
        'username' => 'renamed-account',
    ]);
    $writer->handle($reconnected, accountObservation('2026-09-24', 12));

    $differentIdentity = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'provider-account-2',
        'username' => 'shared-name',
    ]);
    $writer->handle($differentIdentity, accountObservation('2026-09-24', 7));

    expect(AnalyticsAccountDailySnapshot::query()
        ->where('social_account_id', $reconnected->id)
        ->value('social_account_key'))->toBe($historicalKey)
        ->and(AnalyticsAccountDailySnapshot::query()
            ->where('social_account_id', $differentIdentity->id)
            ->value('social_account_key'))->toBe($differentIdentity->id);
});

test('carried forward observations retain provider time and record a new collection time', function () {
    $account = SocialAccount::factory()->x()->create();
    $providerObservedAt = CarbonImmutable::parse('2026-09-22 02:00:00', 'UTC');
    $collectedAt = CarbonImmutable::parse('2026-09-23 23:30:00', 'UTC');

    $snapshot = app(WriteAccountDailySnapshot::class)->handle(
        $account,
        new AccountDailyObservation(
            date: CarbonImmutable::parse('2026-09-23', 'UTC'),
            followers: 42,
            provenance: ObservationProvenance::CarriedForward,
            precision: MetricPrecision::Exact,
            providerObservedAt: $providerObservedAt,
            collectedAt: $collectedAt,
        ),
    );

    expect($snapshot->provider_observed_at?->toImmutable()->equalTo($providerObservedAt))->toBeTrue()
        ->and($snapshot->collected_at->toImmutable()->equalTo($collectedAt))->toBeTrue();
});

test('publication observations merge same-day metrics without erasing successful values', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $publication = AnalyticsPublication::query()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => Platform::Instagram,
        'provider_post_id' => 'provider-post-1',
        'provider_published_at' => CarbonImmutable::parse('2026-09-20 10:00:00', 'UTC'),
        'origin' => PublicationOrigin::External,
        'content_type' => PublicationContentType::Reel,
        'availability' => PublicationAvailability::Available,
        'first_seen_at' => CarbonImmutable::parse('2026-09-23 02:00:00', 'UTC'),
        'last_seen_at' => CarbonImmutable::parse('2026-09-23 02:00:00', 'UTC'),
    ]);
    $writer = app(WritePublicationDailySnapshot::class);

    $writer->handle($publication, publicationObservation('2026-09-23', [
        metric(MetricKey::Reactions, 0),
        metric(MetricKey::Comments, null, MetricAvailability::Unavailable),
        metric(MetricKey::Shares, 4),
        metric(MetricKey::Saves, 2),
        metric(MetricKey::Views, 10),
        metric(MetricKey::Impressions, 20),
        metric(MetricKey::Reach, 8),
        metric(MetricKey::Engagements, 6),
        metric(MetricKey::WatchTimeMilliseconds, 65000, unit: MetricUnit::Milliseconds),
        metric(MetricKey::AverageWatchTimeMilliseconds, 2000, unit: MetricUnit::Milliseconds),
    ]));
    $writer->handle($publication, publicationObservation('2026-09-23', [
        metric(MetricKey::Reactions, null, MetricAvailability::Delayed),
        metric(MetricKey::Comments, 3),
    ]));

    $snapshot = AnalyticsPublicationDailySnapshot::sole();

    expect($snapshot->reactions_count)->toBe(0)
        ->and($snapshot->comments_count)->toBe(3)
        ->and($snapshot->shares_count)->toBe(4)
        ->and($snapshot->saves_count)->toBe(2)
        ->and($snapshot->views_count)->toBe(10)
        ->and($snapshot->impressions_count)->toBe(20)
        ->and($snapshot->reach_count)->toBe(8)
        ->and($snapshot->engagement_count)->toBe(6)
        ->and($snapshot->exposure_count)->toBe(8)
        ->and($snapshot->exposure_kind?->value)->toBe('reach')
        ->and($snapshot->watch_time_milliseconds)->toBe(65000)
        ->and($snapshot->average_watch_time_milliseconds)->toBe(2000)
        ->and(data_get($snapshot->metrics, 'reactions.value'))->toBe(0)
        ->and(data_get($snapshot->metrics, 'comments.value'))->toBe(3)
        ->and(data_get($snapshot->metrics, 'reactions.availability'))->toBe('available');

    $writer->handle($publication, publicationObservation('2026-09-24', [
        metric(MetricKey::Views, 11),
    ]));

    expect(AnalyticsPublicationDailySnapshot::count())->toBe(2);
});

function accountObservation(string $date, ?int $followers): AccountDailyObservation
{
    return new AccountDailyObservation(
        date: CarbonImmutable::parse($date, 'UTC'),
        followers: $followers,
        provenance: ObservationProvenance::Actual,
        precision: MetricPrecision::Exact,
        providerObservedAt: CarbonImmutable::parse("{$date} 02:00:00", 'UTC'),
        collectedAt: CarbonImmutable::parse("{$date} 02:01:00", 'UTC'),
    );
}

function publicationObservation(string $date, array $metrics): PublicationMetricObservation
{
    return new PublicationMetricObservation(
        date: CarbonImmutable::parse($date, 'UTC'),
        metrics: $metrics,
        providerObservedAt: CarbonImmutable::parse("{$date} 02:00:00", 'UTC'),
        collectedAt: CarbonImmutable::parse("{$date} 02:01:00", 'UTC'),
    );
}

function metric(
    MetricKey $key,
    int|float|null $value,
    MetricAvailability $availability = MetricAvailability::Available,
    MetricUnit $unit = MetricUnit::Count,
): MetricValue {
    return new MetricValue(
        key: $key,
        value: $value,
        unit: $unit,
        timeBasis: MetricTimeBasis::Lifetime,
        precision: MetricPrecision::Exact,
        availability: $availability,
        providerMetric: $key->value,
    );
}
