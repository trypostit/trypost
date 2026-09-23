<?php

declare(strict_types=1);

use App\Dto\Analytics\DateRange;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Queries\Analytics\PublicationAnalyticsQuery;
use App\Queries\Analytics\WorkspaceAnalyticsQuery;
use App\Support\Analytics\PeriodBuckets;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

function analyticsReportAccount(Workspace $workspace, Platform $platform): SocialAccount
{
    return SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => $platform]);
}

function analyticsReportFollower(SocialAccount $account, string $date, int $followers, ObservationProvenance $provenance = ObservationProvenance::Actual): void
{
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'snapshot_date' => $date,
        'followers_count' => $followers,
        'provenance' => $provenance,
    ]);
}

function analyticsReportPublication(SocialAccount $account, string $publishedAt, ?int $reactions, ?int $comments, ?int $engagement, ?int $exposure): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $publication->id,
        'snapshot_date' => '2026-09-12',
        'reactions_count' => $reactions,
        'comments_count' => $comments,
        'engagement_count' => $engagement,
        'exposure_count' => $exposure,
    ]);

    return $publication;
}

test('workspace report keeps accounts separate and aggregates only latest normalized facts', function () {
    $workspace = Workspace::factory()->create();
    $instagramA = analyticsReportAccount($workspace, Platform::Instagram);
    $instagramB = analyticsReportAccount($workspace, Platform::Instagram);
    $x = analyticsReportAccount($workspace, Platform::X);
    $linkedIn = analyticsReportAccount($workspace, Platform::LinkedIn);
    $foreign = analyticsReportAccount(Workspace::factory()->create(), Platform::Instagram);

    foreach ([[$instagramA, 95], [$instagramB, 19], [$x, 45]] as [$account, $followers]) {
        analyticsReportFollower($account, '2026-08-31', $followers);
    }
    analyticsReportFollower($instagramA, '2026-09-10', 100);
    analyticsReportFollower($instagramB, '2026-09-10', 20, ObservationProvenance::CarriedForward);
    analyticsReportFollower($x, '2026-09-10', 50);
    analyticsReportFollower($linkedIn, '2026-09-10', 1000);
    analyticsReportFollower($foreign, '2026-09-10', 999);

    $first = analyticsReportPublication($instagramA, '2026-09-05 10:00:00', 10, 2, 12, 100);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $first->id,
        'snapshot_date' => '2026-09-11',
        'reactions_count' => 999,
        'comments_count' => 999,
        'engagement_count' => 999,
        'exposure_count' => 999,
    ]);
    analyticsReportPublication($instagramB, '2026-09-06 10:00:00', 5, 1, 6, 900);
    analyticsReportPublication($x, '2026-09-07 10:00:00', null, 0, null, null);
    analyticsReportPublication($instagramA, '2026-08-25 10:00:00', 2, 1, 3, 30);
    analyticsReportPublication($linkedIn, '2026-09-05 10:00:00', 900, 900, 900, 1);
    analyticsReportPublication($foreign, '2026-09-05 10:00:00', 900, 900, 900, 1);

    $report = app(WorkspaceAnalyticsQuery::class)->for(
        $workspace,
        new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC')),
    );

    expect(array_keys($report['summary']))->toBe(['posts', 'followers', 'reactions', 'comments', 'engagement_rate'])
        ->and($report['summary']['posts']['value'])->toBe(3)
        ->and($report['summary']['posts']['change'])->toBe(200.0)
        ->and($report['summary']['followers']['value'])->toBe(170)
        ->and($report['summary']['followers']['change'])->toBe(11)
        ->and($report['summary']['reactions']['value'])->toBe(15)
        ->and($report['summary']['comments']['value'])->toBe(3)
        ->and($report['summary']['engagement_rate']['value'])->toBe(1.8)
        ->and($report['bounds'])->toBe(['min' => '2026-08-25', 'max' => '2026-09-10'])
        ->and($report['posts']['resolution'])->toBe('daily')
        ->and(count($report['posts']['buckets']))->toBe(10)
        ->and(array_column($report['posts']['buckets'], 'total'))->toBe([0, 0, 0, 0, 1, 1, 1, 0, 0, 0])
        ->and(array_key_exists('label', $report['posts']['buckets'][0]))->toBeFalse()
        ->and(count($report['performance']))->toBe(3)
        ->and(count($report['followers']['accounts']))->toBe(3)
        ->and($report['followers']['total'])->toBe(170)
        ->and($report['top_posts']['reactions'][0]['social_account_key'])->toBe($instagramA->id)
        ->and($report['top_posts']['reactions'][0]['reactions'])->toBe(10);

    $performanceKeys = array_column($report['performance'], 'social_account_key');
    expect($performanceKeys)->toContain($instagramA->id, $instagramB->id, $x->id);
});

test('range boundaries and bucket resolutions are deterministic', function (int $days, string $resolution) {
    $range = new DateRange(
        CarbonImmutable::parse('2026-09-23', 'UTC')->subDays($days - 1),
        CarbonImmutable::parse('2026-09-23', 'UTC'),
    );

    expect(app(PeriodBuckets::class)->resolution($range))->toBe($resolution)
        ->and($range->previous()->days())->toBe($days);
})->with([[14, 'daily'], [15, 'weekly'], [90, 'weekly'], [91, 'monthly']]);

test('invalid reversed date range is rejected', function () {
    expect(fn () => new DateRange(
        CarbonImmutable::parse('2026-09-10', 'UTC'),
        CarbonImmutable::parse('2026-09-01', 'UTC'),
    ))->toThrow(InvalidArgumentException::class);
});

test('a deleted social account retains its historical performance row', function () {
    $workspace = Workspace::factory()->create();
    $account = analyticsReportAccount($workspace, Platform::Instagram);
    analyticsReportPublication($account, '2026-09-05 10:00:00', 0, null, 0, 100);
    $account->delete();

    $report = app(WorkspaceAnalyticsQuery::class)->for(
        $workspace,
        new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC')),
    );

    expect($report['performance'][0]['social_account_key'])->toBe($account->id)
        ->and($report['summary']['reactions']['value'])->toBe(0)
        ->and($report['summary']['comments']['value'])->toBeNull();
});

test('workspace report keeps only the five highest-ranked posts while streaming publication rows', function () {
    $workspace = Workspace::factory()->create();
    $account = analyticsReportAccount($workspace, Platform::Instagram);
    $publications = [];

    foreach ([0, 7, 2, 5, 9, 3, 8] as $reactions) {
        $publications[$reactions] = analyticsReportPublication(
            $account,
            '2026-09-05 10:00:00',
            $reactions,
            0,
            $reactions,
            100,
        );
    }

    $report = app(WorkspaceAnalyticsQuery::class)->for(
        $workspace,
        new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC')),
    );

    expect($report['summary']['posts']['value'])->toBe(7)
        ->and($report['summary']['reactions']['value'])->toBe(34)
        ->and($report['posts']['buckets'][4]['total'])->toBe(7)
        ->and($report['performance'][0]['posts']['value'])->toBe(7)
        ->and(array_column($report['top_posts']['reactions'], 'id'))->toBe(array_map(
            fn (int $reactions): string => $publications[$reactions]->id,
            [9, 8, 7, 5, 3],
        ));
});

test('publication detail uses the latest persisted metric snapshot without provider reads', function () {
    Http::fake();
    $workspace = Workspace::factory()->create();
    $account = analyticsReportAccount($workspace, Platform::Instagram);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $destination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $destination->id,
        'platform' => Platform::Instagram,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $publication->id,
        'snapshot_date' => '2026-09-10',
        'reactions_count' => 50,
        'metrics' => ['reactions' => ['value' => 50, 'unit' => 'count', 'availability' => 'available']],
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $publication->id,
        'snapshot_date' => '2026-09-11',
        'reactions_count' => 0,
        'watch_time_milliseconds' => 185000,
        'metrics' => [
            'reactions' => ['value' => 0, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 185000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);

    $detail = app(PublicationAnalyticsQuery::class)->latestForPostPlatform($destination);

    expect($detail['available'])->toBeTrue()
        ->and($detail['publication']['content_type'])->toBe('reel')
        ->and($detail['snapshot']['reactions_count'])->toBe(0)
        ->and($detail['snapshot']['watch_time_milliseconds'])->toBe(185000)
        ->and($detail['metrics']['watch_time_milliseconds']['unit'])->toBe('milliseconds');
    Http::assertNothingSent();
});

test('publication detail remains scoped to the post workspace and excludes unsupported networks', function () {
    $workspace = Workspace::factory()->create();
    $foreign = analyticsReportAccount(Workspace::factory()->create(), Platform::Instagram);
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $destination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $foreign->id,
    ]);
    AnalyticsPublication::factory()->create([
        'workspace_id' => $foreign->workspace_id,
        'post_platform_id' => $destination->id,
        'platform' => Platform::Instagram,
    ]);

    expect(app(PublicationAnalyticsQuery::class)->latestForPostPlatform($destination)['available'])->toBeFalse();

    $destination->update(['platform' => Platform::LinkedIn]);
    expect(app(PublicationAnalyticsQuery::class)->latestForPostPlatform($destination)['reason'])->toBe('platform_not_supported');
});

test('external YouTube upload stays a video and not a short without authoritative metadata', function () {
    $workspace = Workspace::factory()->create();
    $account = analyticsReportAccount($workspace, Platform::YouTube);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::YouTube,
        'content_type' => PublicationContentType::Video,
    ]);

    $detail = app(PublicationAnalyticsQuery::class)->latestForPublication($publication);

    expect($detail['publication']['content_type'])->toBe('video')
        ->and($detail['snapshot'])->toBeNull();
});

test('dashboard query count is independent of the number of account rows', function () {
    $workspace = Workspace::factory()->create();
    $range = new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC'));
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app(WorkspaceAnalyticsQuery::class)->for($workspace, $range);
    $baseCount = count($queries);
    $queries = [];

    foreach (range(1, 3) as $index) {
        $account = analyticsReportAccount($workspace, Platform::Instagram);
        analyticsReportFollower($account, '2026-09-10', $index);
        analyticsReportPublication($account, '2026-09-05 10:00:00', $index, 0, $index, 100);
    }
    $queries = [];
    app(WorkspaceAnalyticsQuery::class)->for($workspace, $range);

    expect(count($queries))->toBe($baseCount);
});
