<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildChannelMetricSeries;
use App\Actions\Analytics\BuildFollowerGrowthRateSeries;
use App\Dto\Analytics\DateRange;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-09-30 12:00 UTC');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);
});

/**
 * @param  array<string, mixed>  $metrics
 */
function seriesPublication(object $test, SocialAccount $account, string $publishedAt, array $metrics = [], ?WorkspaceLabel $label = null): AnalyticsPublication
{
    $post = null;

    if ($label !== null) {
        $post = Post::factory()->forAccount($account, ContentType::InstagramFeed)->published()->create(['user_id' => $test->user->id]);
        $post->labels()->attach($label);
    }

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'post_id' => $post?->id,
        'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => CarbonImmutable::parse($publishedAt, 'UTC')->toDateString(),
        ...$metrics,
    ]);

    return $publication;
}

function seriesFollowers(SocialAccount $account, string $date, int $followers): void
{
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => $date,
        'followers_count' => $followers,
    ]);
}

/** @return array<string, mixed> */
function profileVisits(int $visits): array
{
    return ['metrics' => ['profile_visits' => ['value' => $visits, 'unit' => 'count', 'availability' => 'available']]];
}

function seriesRange(string $start, string $end): DateRange
{
    return new DateRange(CarbonImmutable::parse($start, 'UTC'), CarbonImmutable::parse($end, 'UTC'));
}

/**
 * @return array<string, mixed>
 */
function buildSeries(SocialAccount $account, DateRange $range, WeekStart $weekStart = WeekStart::Monday, ?PublicationFilter $filter = null): array
{
    return app(BuildChannelMetricSeries::class)->handle($account, $account->id, $range, $weekStart, $filter);
}

test('an Instagram channel gets daily post, follower and visibility series for both periods', function () {
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 100, 'views_count' => 300, ...profileVisits(4)]);
    seriesPublication($this, $this->instagram, '2026-09-22 18:00:00', ['reach_count' => 50, 'views_count' => 70, ...profileVisits(1)]);
    seriesPublication($this, $this->instagram, '2026-09-25 10:00:00', ['reach_count' => 10]);
    seriesPublication($this, $this->instagram, '2026-09-17 10:00:00', ['reach_count' => 40, 'views_count' => 5]);
    seriesFollowers($this->instagram, '2026-09-20', 1000);
    seriesFollowers($this->instagram, '2026-09-23', 1010);
    seriesFollowers($this->instagram, '2026-09-26', 1025);

    $series = buildSeries($this->instagram, seriesRange('2026-09-21', '2026-09-27'));

    expect($series['resolution'])->toBe('daily')
        ->and($series['metrics'])->toBe(['posts', 'followers', 'net_followers', 'reach', 'views', 'profile_visits'])
        ->and($series['range'])->toBe(['start' => '2026-09-21', 'end' => '2026-09-27'])
        ->and($series['previous_range'])->toBe(['start' => '2026-09-14', 'end' => '2026-09-20'])
        ->and($series['current'])->toHaveCount(7)
        ->and($series['previous'])->toHaveCount(7)
        ->and(array_column($series['current'], 'start'))->toBe(['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25', '2026-09-26', '2026-09-27'])
        ->and(array_column($series['previous'], 'start'))->toBe(['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20'])
        ->and(array_map(fn (array $point): int => $point['values']['posts'], $series['current']))->toBe([0, 2, 0, 0, 1, 0, 0])
        ->and(array_map(fn (array $point): int => $point['values']['reach'], $series['current']))->toBe([0, 150, 0, 0, 10, 0, 0])
        ->and($series['current'][1]['values']['profile_visits'])->toBe(5)
        ->and(array_map(fn (array $point): ?int => $point['values']['followers'], $series['current']))->toBe([1000, 1000, 1010, 1010, 1010, 1025, 1025])
        ->and(array_map(fn (array $point): ?int => $point['values']['net_followers'], $series['current']))->toBe([0, 0, 10, 10, 10, 25, 25])
        ->and(array_map(fn (array $point): ?int => $point['values']['followers'], $series['previous']))->toBe([null, null, null, null, null, null, 1000])
        ->and($series['previous'][3]['values']['reach'])->toBe(40)
        ->and($series['totals']['current'])->toBe([
            'posts' => 3, 'followers' => 1025, 'net_followers' => 25, 'reach' => 160, 'views' => 370, 'profile_visits' => 5,
        ])
        ->and($series['totals']['previous'])->toBe([
            'posts' => 1, 'followers' => 1000, 'net_followers' => null, 'reach' => 40, 'views' => 5, 'profile_visits' => 0,
        ]);
});

test('followers carry the last count from before the window forward', function () {
    seriesFollowers($this->instagram, '2026-08-01', 700);
    seriesFollowers($this->instagram, '2026-09-25', 760);

    $series = buildSeries($this->instagram, seriesRange('2026-09-24', '2026-09-26'));

    expect(array_map(fn (array $point): ?int => $point['values']['followers'], $series['current']))->toBe([700, 760, 760])
        ->and(array_map(fn (array $point): ?int => $point['values']['net_followers'], $series['current']))->toBe([0, 60, 60])
        ->and(array_map(fn (array $point): ?int => $point['values']['followers'], $series['previous']))->toBe([700, 700, 700])
        ->and($series['totals']['previous']['net_followers'])->toBe(0);
});

test('a single follower observation has no net change, in the series and in the summary', function () {
    seriesFollowers($this->instagram, '2026-09-25', 800);

    $series = buildSeries($this->instagram, seriesRange('2026-09-21', '2026-09-27'));

    expect(array_map(fn (array $point): ?int => $point['values']['followers'], $series['current']))->toBe([null, null, null, null, 800, 800, 800])
        ->and(array_map(fn (array $point): ?int => $point['values']['net_followers'], $series['current']))->toBe([null, null, null, null, null, null, null])
        ->and($series['totals']['current']['net_followers'])->toBeNull();

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-09-21', 'end' => '2026-09-27']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.net_followers.value', null)
            ->where('report.summary.followers.change', null)
            ->where('availableMetrics', fn ($metrics): bool => ! collect($metrics)->contains('net_followers'))
            ->etc());
});

test('availability resolved once for the request is reused by the series', function () {
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 100]);

    $series = app(BuildChannelMetricSeries::class)->handle(
        $this->instagram,
        $this->instagram->id,
        seriesRange('2026-09-21', '2026-09-27'),
        availability: ['posts' => true, 'followers' => true, 'reach' => false, 'views' => true],
    );

    expect($series['metrics'])->toBe(['posts', 'followers', 'net_followers', 'views'])
        ->and($series['totals']['current']['views'])->toBe(0);
});

test('workspace follower rows carry the net change measured from the last count before the range', function () {
    seriesFollowers($this->instagram, '2026-09-01', 900);
    seriesFollowers($this->instagram, '2026-09-25', 940);
    seriesFollowers($this->instagram, '2026-09-27', 930);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-09-21', 'end' => '2026-09-27']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.followers.accounts.0.net', 30)
            ->where('report.followers.accounts.0.growth', -10)
            ->where('report.summary.net_followers.value', 30)
            ->etc());
});

test('a label narrows the post series but leaves followers account-wide', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 100], $label);
    seriesPublication($this, $this->instagram, '2026-09-23 10:00:00', ['reach_count' => 30]);
    seriesPublication($this, $this->instagram, '2026-09-15 10:00:00', ['reach_count' => 9], $label);
    seriesPublication($this, $this->instagram, '2026-09-16 10:00:00', ['reach_count' => 8]);
    seriesFollowers($this->instagram, '2026-09-21', 500);
    seriesFollowers($this->instagram, '2026-09-27', 520);

    $series = buildSeries($this->instagram, seriesRange('2026-09-21', '2026-09-27'), filter: new PublicationFilter(Platform::Instagram, [$label->id]));

    expect($series['totals']['current']['posts'])->toBe(1)
        ->and($series['totals']['current']['reach'])->toBe(100)
        ->and($series['totals']['previous']['posts'])->toBe(1)
        ->and($series['totals']['previous']['reach'])->toBe(9)
        ->and($series['totals']['current']['followers'])->toBe(520)
        ->and($series['totals']['current']['net_followers'])->toBe(20);
});

test('a post type filter narrows the post series', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 100], $label);
    seriesPublication($this, $this->instagram, '2026-09-23 10:00:00', ['reach_count' => 30]);

    $reels = buildSeries($this->instagram, seriesRange('2026-09-21', '2026-09-27'), filter: new PublicationFilter(Platform::Instagram, contentTypes: [ContentType::InstagramReel]));
    $feed = buildSeries($this->instagram, seriesRange('2026-09-21', '2026-09-27'), filter: new PublicationFilter(Platform::Instagram, contentTypes: [ContentType::InstagramFeed]));

    expect($reels['totals']['current']['posts'])->toBe(0)
        ->and($feed['totals']['current']['posts'])->toBe(2)
        ->and($feed['totals']['current']['reach'])->toBe(130);
});

test('long ranges are bucketed by week ending on the viewer week start', function (WeekStart $weekStart, string $firstEnd) {
    seriesPublication($this, $this->instagram, '2026-06-01 10:00:00', ['reach_count' => 5]);
    seriesPublication($this, $this->instagram, '2026-09-29 10:00:00', ['reach_count' => 7]);

    $series = buildSeries($this->instagram, seriesRange('2026-06-01', '2026-09-30'), $weekStart);

    expect($series['resolution'])->toBe('weekly')
        ->and($series['current'][0]['start'])->toBe('2026-06-01')
        ->and($series['current'][0]['end'])->toBe($firstEnd)
        ->and(end($series['current'])['end'])->toBe('2026-09-30')
        ->and(count($series['previous']))->toBe(count($series['current']))
        ->and($series['totals']['current']['posts'])->toBe(2)
        ->and($series['totals']['current']['reach'])->toBe(12)
        ->and($series['current'][0]['values']['posts'])->toBe(1);
})->with([
    'Monday' => [WeekStart::Monday, '2026-06-07'],
    'Sunday' => [WeekStart::Sunday, '2026-06-06'],
]);

test('ranges up to the daily limit stay daily', function () {
    $series = buildSeries($this->instagram, seriesRange('2026-07-01', '2026-09-30'));

    expect($series['resolution'])->toBe('daily')
        ->and($series['current'])->toHaveCount(92);
});

test('a network without reach or views only offers posts and followers', function () {
    $bluesky = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);
    seriesPublication($this, $bluesky, '2026-09-22 10:00:00', ['reactions_count' => 3]);

    $series = buildSeries($bluesky, seriesRange('2026-09-21', '2026-09-27'));

    expect($series['metrics'])->toBe(['posts', 'followers', 'net_followers'])
        ->and(array_keys($series['current'][0]['values']))->toBe(['posts', 'followers', 'net_followers'])
        ->and($series['totals']['current'])->toBe(['posts' => 1, 'followers' => null, 'net_followers' => null]);
});

test('an X channel offers its impressions', function () {
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    seriesPublication($this, $x, '2026-09-22 10:00:00', ['impressions_count' => 900]);

    $series = buildSeries($x, seriesRange('2026-09-21', '2026-09-27'));

    expect($series['metrics'])->toBe(['posts', 'followers', 'net_followers', 'impressions'])
        ->and($series['totals']['current']['impressions'])->toBe(900);
});

test('the insights page defers the metric series and loads it with the page filters', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 100], $label);
    seriesPublication($this, $this->instagram, '2026-09-23 10:00:00', ['reach_count' => 30]);
    $url = route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', 'labels' => [$label->id]]);

    $this->actingAs($this->user)
        ->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('metricSeries')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('metricSeries.resolution', 'daily')
                ->where('metricSeries.range', ['start' => '2026-09-22', 'end' => '2026-09-23'])
                ->where('metricSeries.totals.current.posts', 1)
                ->where('metricSeries.totals.current.reach', 100)
                ->has('metricSeries.current', 2)
                ->has('metricSeries.previous', 2)));
});

test('the summary reports net new followers and an absolute followers change once there is history', function () {
    seriesFollowers($this->instagram, '2026-09-10', 400);
    seriesFollowers($this->instagram, '2026-09-29', 430);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.followers.value', 430)
            ->where('report.summary.followers.previous', null)
            ->where('report.summary.followers.change', 30)
            ->where('report.summary.net_followers.value', 30)
            ->where('availableMetrics', fn ($metrics): bool => collect($metrics)->take(3)->values()->all() === ['followers', 'net_followers', 'posts'])
            ->etc());
});

test('quotes, reposts, clicks and impressions become summary tiles and table columns where collected', function () {
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);
    $publication = seriesPublication($this, $x, '2026-09-22 10:00:00', [
        'impressions_count' => 900,
        'metrics' => [
            'quotes' => ['value' => 2, 'unit' => 'count', 'availability' => 'available'],
            'reposts' => ['value' => 5, 'unit' => 'count', 'availability' => 'available'],
            'link_clicks' => ['value' => 7, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $x, 'range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableMetrics', ['followers', 'posts', 'impressions', 'reposts', 'quotes', 'clicks'])
            ->where('report.summary.quotes.value', 2)
            ->where('report.summary.reposts.value', 5)
            ->where('report.summary.clicks.value', 7)
            ->where('report.summary.impressions.value', 900)
            ->where('publications.data.0.id', $publication->id)
            ->where('publications.data.0.metrics.quotes', 2)
            ->where('publications.data.0.metrics.clicks', 7)
            ->where('publications.data.0.metrics.impressions', 900)
            ->etc());
});

test('the follower growth rate compares the last count of each month with the month before over twelve months', function () {
    seriesFollowers($this->instagram, '2025-09-20', 900);
    seriesFollowers($this->instagram, '2025-10-31', 1000);
    seriesFollowers($this->instagram, '2026-07-10', 1990);
    seriesFollowers($this->instagram, '2026-07-31', 2000);
    seriesFollowers($this->instagram, '2026-08-15', 2030);
    seriesFollowers($this->instagram, '2026-08-31', 2050);
    seriesFollowers($this->instagram, '2026-09-29', 2091);

    $growth = app(BuildFollowerGrowthRateSeries::class)->handle($this->workspace->id, $this->instagram->id, CarbonImmutable::parse('2026-09-30', 'UTC'));

    expect($growth['range'])->toBe(['start' => '2025-10-01', 'end' => '2026-09-30'])
        ->and($growth['months'])->toHaveCount(BuildFollowerGrowthRateSeries::MONTHS)
        ->and($growth['months'][0])->toEqual(['month' => '2025-10', 'start' => '2025-10-01', 'end' => '2025-10-31', 'followers' => 1000, 'rate' => 11.11])
        ->and($growth['months'][1]['rate'])->toBeNull()
        ->and($growth['months'][9]['rate'])->toBeNull()
        ->and($growth['months'][10]['rate'])->toEqual(2.5)
        ->and($growth['months'][11])->toEqual(['month' => '2026-09', 'start' => '2026-09-01', 'end' => '2026-09-30', 'followers' => 2091, 'rate' => 2.0])
        ->and($growth['latest'])->toEqual(2.0)
        ->and($growth['previous'])->toEqual(2.5);
});

test('a month after a month without followers or with zero followers has no growth rate', function () {
    seriesFollowers($this->instagram, '2026-07-31', 0);
    seriesFollowers($this->instagram, '2026-08-31', 50);

    $growth = app(BuildFollowerGrowthRateSeries::class)->handle($this->workspace->id, $this->instagram->id, CarbonImmutable::parse('2026-09-30', 'UTC'));

    expect(array_column($growth['months'], 'rate'))->each->toBeNull()
        ->and($growth['latest'])->toBeNull()
        ->and($growth['previous'])->toBeNull();
});

test('the deferred series carries the monthly growth rate, which ignores the page filters', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    seriesFollowers($this->instagram, '2026-07-31', 1000);
    seriesFollowers($this->instagram, '2026-08-31', 1100);
    seriesFollowers($this->instagram, '2026-09-29', 1210);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d', 'labels' => [$label->id], 'types' => ['instagram_reel']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('metricSeries.growth.range', ['start' => '2025-10-01', 'end' => '2026-09-30'])
                ->has('metricSeries.growth.months', 12)
                ->where('metricSeries.growth.months.11.rate', 10)
                ->where('metricSeries.growth.latest', 10)
                ->where('metricSeries.growth.previous', 10)));
});

test('a channel without follower history has no growth rate', function () {
    seriesPublication($this, $this->instagram, '2026-09-22 10:00:00', ['reach_count' => 10]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('metricSeries.growth', null)));
});
