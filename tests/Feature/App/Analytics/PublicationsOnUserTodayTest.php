<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-10-02 01:30:00 UTC');

    $this->workspace = Workspace::factory()->create();
    $this->account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->account->id,
        'social_account_key' => $this->account->id,
        'network' => $this->account->platform->network(),
        'platform_user_id' => $this->account->platform_user_id,
        'platform' => $this->account->platform,
        'provider_published_at' => CarbonImmutable::parse('2026-10-02 01:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $this->publication->id,
        'date' => '2026-10-02',
        'reactions_count' => 7,
    ]);
});

/** @return array{start?: string, end?: string, observed_through?: string} */
function publicationsOnUserTodaySelection(string $range): array
{
    return app(ResolveAnalyticsRangePreset::class)->selection(['range' => $range], 'America/Sao_Paulo')['selection'];
}

test('a post published late in the user evening counts in a preset ending on the user today', function (string $range) {
    $report = app(BuildWorkspaceAnalyticsReport::class)->forSelection($this->workspace, publicationsOnUserTodaySelection($range), clampToBounds: false);
    $lastBucket = collect(data_get($report, 'posts.buckets'))->last();

    expect(data_get($report, 'range.end'))->toBe('2026-10-01')
        ->and(data_get($report, 'summary.posts.value'))->toBe(1)
        ->and(data_get($report, 'summary.reactions.value'))->toBe(7)
        ->and(data_get($report, 'posts.accounts.0.count'))->toBe(1)
        ->and(data_get($lastBucket, 'total'))->toBe(1)
        ->and(data_get($report, 'top_posts.reactions.0.id'))->toBe($this->publication->id);
})->with(['7d', '30d', 'mtd']);

test('channel insights list a post published late in the user evening', function () {
    $analytics = app(BuildWorkspaceAnalyticsReport::class);
    ['range' => $range, 'bounds' => $bounds] = $analytics->resolveRange($this->workspace, publicationsOnUserTodaySelection('7d'), [$this->account->id], false);

    expect(data_get($analytics->execute($this->workspace, $range, $bounds, $this->account, $this->account->id), 'summary.posts.value'))->toBe(1)
        ->and(app(ListChannelPublicationPerformance::class)->handle($this->account, $range, 'reactions', $this->account->id)->pluck('id')->all())
        ->toBe([$this->publication->id]);
});

test('a range that ended before the user today does not take a later post', function () {
    $report = app(BuildWorkspaceAnalyticsReport::class)->forSelection(
        $this->workspace,
        ['start' => '2026-09-24', 'end' => '2026-09-30'],
        clampToBounds: false,
    );

    expect(data_get($report, 'summary.posts.value'))->toBe(0)
        ->and(app(ListChannelPublicationPerformance::class)->handle(
            $this->account,
            app(BuildWorkspaceAnalyticsReport::class)->resolveRange($this->workspace, ['start' => '2026-09-01', 'end' => '2026-09-30'], [$this->account->id], false)['range'],
            'reactions',
            $this->account->id,
        )->total())->toBe(0);
});
