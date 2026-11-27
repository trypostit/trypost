<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\GetAnalyticsBounds;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

function channelScopedAccount(Workspace $workspace): SocialAccount
{
    return SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
}

function channelScopedFollower(SocialAccount $account, string $date, int $followers): AnalyticsAccountDailySnapshot
{
    return AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'date' => $date,
        'followers_count' => $followers,
    ]);
}

/** @param array<string, mixed> $metrics */
function channelScopedPublication(SocialAccount $account, string $publishedAt, array $metrics = []): AnalyticsPublication
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
        'publication_id' => $publication->id,
        'date' => '2026-09-12',
        ...$metrics,
    ]);

    return $publication;
}

function channelScopedRange(): DateRange
{
    return new DateRange(CarbonImmutable::parse('2026-09-01', 'UTC'), CarbonImmutable::parse('2026-09-10', 'UTC'));
}

/** @return array{workspace: Workspace, first: SocialAccount, second: SocialAccount} */
function channelScopedFixture(): array
{
    $workspace = Workspace::factory()->create();
    $first = channelScopedAccount($workspace);
    $second = channelScopedAccount($workspace);

    channelScopedFollower($first, '2026-08-31', 90);
    channelScopedFollower($first, '2026-09-10', 100);
    channelScopedFollower($second, '2026-08-31', 15);
    channelScopedFollower($second, '2026-09-10', 20);

    channelScopedPublication($first, '2026-09-03 10:00:00', ['reactions_count' => 10, 'comments_count' => 2]);
    channelScopedPublication($first, '2026-09-05 10:00:00', ['reactions_count' => 4, 'comments_count' => 1]);
    channelScopedPublication($second, '2026-09-06 10:00:00', ['reactions_count' => 7, 'comments_count' => 3]);

    return ['workspace' => $workspace, 'first' => $first, 'second' => $second];
}

test('a channel-scoped report counts only that channel publications and followers', function () {
    ['workspace' => $workspace, 'first' => $first] = channelScopedFixture();

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $first);

    expect($report['summary']['posts']['value'])->toBe(2)
        ->and($report['summary']['reactions']['value'])->toBe(14)
        ->and($report['summary']['comments']['value'])->toBe(3)
        ->and($report['summary']['followers']['value'])->toBe(100)
        ->and($report['summary']['followers']['change'])->toBe(10)
        ->and(array_column($report['followers']['accounts'], 'social_account_key'))->toBe([$first->id])
        ->and(array_column($report['performance'], 'social_account_key'))->toBe([$first->id]);
});

test('a channel-scoped report keeps rows whose social account id was cleared', function () {
    ['workspace' => $workspace, 'first' => $first] = channelScopedFixture();
    AnalyticsPublication::query()->where('social_account_key', $first->id)->update(['social_account_id' => null]);
    AnalyticsAccountDailySnapshot::query()->where('social_account_key', $first->id)->update(['social_account_id' => null]);

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $first);

    expect($report['summary']['posts']['value'])->toBe(2)
        ->and($report['summary']['reactions']['value'])->toBe(14)
        ->and($report['summary']['comments']['value'])->toBe(3)
        ->and($report['summary']['followers']['value'])->toBe(100);
});

test('the unscoped report equals the sum over every channel', function () {
    ['workspace' => $workspace, 'first' => $first, 'second' => $second] = channelScopedFixture();
    $build = app(BuildWorkspaceAnalyticsReport::class);

    $workspaceReport = $build->execute($workspace, channelScopedRange());
    $firstReport = $build->execute($workspace, channelScopedRange(), null, $first);
    $secondReport = $build->execute($workspace, channelScopedRange(), null, $second);

    foreach (['posts', 'reactions', 'comments'] as $metric) {
        expect($workspaceReport['summary'][$metric]['value'])
            ->toBe($firstReport['summary'][$metric]['value'] + $secondReport['summary'][$metric]['value']);
    }

    expect(array_keys($workspaceReport))->toBe(['bounds', 'range', 'previous_range', 'summary', 'followers', 'posts', 'top_posts', 'performance', 'coverage'])
        ->and(array_keys($workspaceReport['summary']))->toBe([
            'posts', 'followers', 'net_followers', 'reactions', 'comments', 'engagement_rate', 'views', 'impressions',
            'clicks', 'reposts', 'quotes', 'reach', 'shares', 'saves', 'watch_time_minutes', 'average_watch_time_seconds', 'follows_gained',
        ])
        ->and($workspaceReport['summary']['followers']['value'])->toBe(120)
        ->and(count($workspaceReport['performance']))->toBe(2);
});

test('a metric only some of five in-range posts report sums only those posts', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);

    foreach ([100, 50, null, null, null] as $index => $views) {
        $day = $index + 1;
        channelScopedPublication($account, "2026-09-0{$day} 10:00:00", ['views_count' => $views]);
    }

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $account);
    $available = app(ListAvailableChannelMetrics::class)->handle($account);

    expect($report['summary']['posts']['value'])->toBe(5)
        ->and($report['summary']['views']['value'])->toBe(150)
        ->and($report['summary']['saves']['value'])->toBeNull()
        ->and($available)->toBe(['followers', 'posts', 'views'])
        ->and($available)->not->toContain('saves');
});

test('watch time is summed in minutes and average watch time is averaged in seconds', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    channelScopedPublication($account, '2026-09-02 10:00:00', [
        'watch_time_milliseconds' => 60000,
        'average_watch_time_milliseconds' => 10000,
    ]);
    channelScopedPublication($account, '2026-09-03 10:00:00', [
        'watch_time_milliseconds' => 30000,
        'average_watch_time_milliseconds' => 20000,
    ]);

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $account);

    expect($report['summary']['watch_time_minutes']['value'])->toBe(1.5)
        ->and($report['summary']['average_watch_time_seconds']['value'])->toBe(15.0)
        ->and(app(ListAvailableChannelMetrics::class)->handle($account))
        ->toBe(['followers', 'posts', 'watch_time_minutes', 'average_watch_time_seconds']);
});

test('follows gained sums the follows metric and is listed as available', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);

    foreach ([3, 2] as $index => $follows) {
        $day = $index + 2;
        channelScopedPublication($account, "2026-09-0{$day} 10:00:00", [
            'metrics' => ['follows' => ['value' => $follows, 'unit' => 'count', 'availability' => 'available']],
        ]);
    }

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $account);

    expect($report['summary']['follows_gained']['value'])->toBe(5)
        ->and(app(ListAvailableChannelMetrics::class)->handle($account))->toBe(['followers', 'posts', 'follows_gained']);
});

test('analytics bounds scoped to a channel only span that channel data', function () {
    ['workspace' => $workspace, 'first' => $first] = channelScopedFixture();
    $second = channelScopedAccount($workspace);
    channelScopedFollower($second, '2026-07-01', 5);
    channelScopedPublication($second, '2026-10-01 10:00:00');

    $accountKey = app(ResolveAnalyticsAccountKey::class)->for($first);

    expect(app(GetAnalyticsBounds::class)->execute($workspace, [$accountKey]))->toBe(['min' => '2026-08-31', 'max' => '2026-09-10'])
        ->and(app(GetAnalyticsBounds::class)->execute($workspace))->toBe(['min' => '2026-07-01', 'max' => '2026-10-01']);
});

test('a reconnected channel still counts the rows stored under its previous key', function () {
    ['workspace' => $workspace, 'first' => $first] = channelScopedFixture();
    $previousKey = $first->id;
    $platformUserId = $first->platform_user_id;
    $first->delete();
    AnalyticsPublication::query()->where('social_account_key', $previousKey)->update(['social_account_id' => null]);
    AnalyticsAccountDailySnapshot::query()->where('social_account_key', $previousKey)->update(['social_account_id' => null]);

    $reconnected = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $platformUserId,
    ]);
    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $reconnected);

    expect($reconnected->id)->not->toBe($previousKey)
        ->and(app(ResolveAnalyticsAccountKey::class)->for($reconnected))->toBe($previousKey)
        ->and($report['summary']['posts']['value'])->toBe(2)
        ->and($report['summary']['reactions']['value'])->toBe(14)
        ->and($report['summary']['followers']['value'])->toBe(100)
        ->and(app(GetAnalyticsBounds::class)->execute($workspace, [$previousKey]))->toBe(['min' => '2026-08-31', 'max' => '2026-09-10'])
        ->and(app(BuildWorkspaceAnalyticsReport::class)->forSelection($workspace, [], [$reconnected->id => $previousKey])['bounds'])
        ->toBe(['min' => '2026-08-31', 'max' => '2026-09-10']);
});

test('a follows entry that is not available is excluded from the report and the available metrics', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    channelScopedPublication($account, '2026-09-02 10:00:00', [
        'views_count' => 10,
        'metrics' => ['follows' => ['value' => 4, 'unit' => 'count', 'availability' => 'privacy_limited']],
    ]);

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, channelScopedRange(), null, $account);

    expect($report['summary']['follows_gained']['value'])->toBeNull()
        ->and($report['summary']['views']['previous'])->toBeNull()
        ->and(app(ListAvailableChannelMetrics::class)->handle($account))->toBe(['followers', 'posts', 'views']);
});

test('available metrics only read the latest snapshot of each publication', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    $publication = channelScopedPublication($account, '2026-09-02 10:00:00', ['views_count' => 10]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-11',
        'saves_count' => 5,
        'metrics' => ['follows' => ['value' => 2, 'unit' => 'count', 'availability' => 'available']],
    ]);

    expect(app(ListAvailableChannelMetrics::class)->handle($account))->toBe(['followers', 'posts', 'views']);
});

test('engagement rate is only available when a snapshot has a positive exposure', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    channelScopedPublication($account, '2026-09-02 10:00:00', ['engagement_count' => 3, 'exposure_count' => 0]);

    expect(app(ListAvailableChannelMetrics::class)->handle($account))->toBe(['followers', 'posts']);

    channelScopedPublication($account, '2026-09-03 10:00:00', ['engagement_count' => 3, 'exposure_count' => 30]);

    expect(app(ListAvailableChannelMetrics::class)->handle($account))->toBe(['followers', 'posts', 'engagement_rate']);
});

test('follows gained is only scanned for platforms whose collectors report follows', function () {
    $workspace = Workspace::factory()->create();
    $facebook = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Facebook]);
    channelScopedPublication($facebook, '2026-09-02 10:00:00', [
        'views_count' => 10,
        'metrics' => ['follows' => ['value' => 4, 'unit' => 'count', 'availability' => 'available']],
    ]);

    expect(Platform::Facebook->reportsPublicationFollows())->toBeFalse()
        ->and(Platform::Instagram->reportsPublicationFollows())->toBeTrue()
        ->and(Platform::InstagramFacebook->reportsPublicationFollows())->toBeTrue()
        ->and(app(ListAvailableChannelMetrics::class)->handle($facebook))->toBe(['followers', 'posts', 'views']);
});

test('follower totals use the latest snapshot on or before each range end', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    channelScopedFollower($account, '2026-09-01', 80);
    channelScopedFollower($account, '2026-09-27', 120);
    $range = new DateRange(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-30'));

    $workspaceReport = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, $range);
    $channelReport = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, $range, null, $account);

    expect($workspaceReport['summary']['followers'])->toBe(['value' => 120, 'previous' => 80, 'change' => 40])
        ->and($workspaceReport['followers']['total'])->toBe(120)
        ->and($workspaceReport['followers']['accounts'][0]['value'])->toBe(120)
        ->and($channelReport['summary']['followers'])->toBe(['value' => 120, 'previous' => 80, 'change' => 40]);
});

test('a follower snapshot before the range start is not carried into the range', function () {
    $workspace = Workspace::factory()->create();
    $account = channelScopedAccount($workspace);
    channelScopedFollower($account, '2026-08-01', 80);
    $range = new DateRange(CarbonImmutable::parse('2026-09-11'), CarbonImmutable::parse('2026-09-30'));

    $report = app(BuildWorkspaceAnalyticsReport::class)->execute($workspace, $range, null, $account);

    expect($report['summary']['followers']['value'])->toBeNull()
        ->and($report['summary']['followers']['previous'])->toBeNull();
});
