<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildPostingActivity;
use App\Dto\Analytics\PublicationFilter;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(now('UTC')->startOfDay()->addHours(12));

    $this->workspace = Workspace::factory()->create();
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $this->today = CarbonImmutable::now('UTC')->startOfDay();
});

function postingActivityPublication(SocialAccount $account, CarbonImmutable $instant, ?Post $post = null): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'post_id' => $post?->id,
        'provider_published_at' => $instant,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $instant->toDateString(),
    ]);

    return $publication;
}

/**
 * @param  list<int>  $daysAgo
 */
function postingActivityPostsOn(SocialAccount $account, CarbonImmutable $today, array $daysAgo): void
{
    foreach ($daysAgo as $days) {
        postingActivityPublication($account, $today->subDays($days)->setTime(12, 0));
    }
}

/**
 * @param  list<string>|null  $accountKeys
 * @return array<string, mixed>
 */
function postingActivity(Workspace $workspace, ?array $accountKeys = null, string $timezone = 'UTC', ?PublicationFilter $filter = null): array
{
    return app(BuildPostingActivity::class)->execute($workspace, $accountKeys, $timezone, $filter);
}

test('it returns one entry per day for the last 365 days, oldest first, zeros included', function () {
    $activity = postingActivity($this->workspace);

    expect($activity['days'])->toHaveCount(365)
        ->and($activity['days'][364])->toBe(['date' => $this->today->toDateString(), 'count' => 0])
        ->and($activity['days'][0]['date'])->toBe($this->today->subDays(364)->toDateString())
        ->and($activity['start'])->toBe($this->today->subDays(364)->toDateString())
        ->and($activity['end'])->toBe($this->today->toDateString());
});

test('it counts a post on the viewer local day', function () {
    postingActivityPublication($this->account, $this->today->setTime(1, 0));

    $activity = postingActivity($this->workspace, timezone: 'America/Sao_Paulo');
    $counts = collect($activity['days'])->pluck('count', 'date');

    expect($counts[$this->today->subDay()->toDateString()])->toBe(1)
        ->and($counts[$this->today->toDateString()])->toBe(0);
});

test('it ignores publications outside the window', function () {
    postingActivityPostsOn($this->account, $this->today, [365]);

    expect(postingActivity($this->workspace)['total'])->toBe(0);
});

test('it keeps the current streak when today has no post yet', function () {
    postingActivityPostsOn($this->account, $this->today, [1, 2, 3]);

    expect(postingActivity($this->workspace)['current_streak'])->toBe(3);
});

test('it counts today in the current streak', function () {
    postingActivityPostsOn($this->account, $this->today, [0, 1]);

    expect(postingActivity($this->workspace)['current_streak'])->toBe(2);
});

test('it resets the current streak when yesterday and today are empty', function () {
    postingActivityPostsOn($this->account, $this->today, [2, 3]);

    $activity = postingActivity($this->workspace);

    expect($activity['current_streak'])->toBe(0)
        ->and($activity['best_streak'])->toBe(2);
});

test('it reports the longest run as the best streak and the busiest day', function () {
    postingActivityPostsOn($this->account, $this->today, [10, 11, 12, 13, 20, 21, 21, 21]);

    $activity = postingActivity($this->workspace);

    expect($activity['best_streak'])->toBe(4)
        ->and($activity['max_count'])->toBe(3)
        ->and($activity['total'])->toBe(8);
});

test('it counts only the given accounts', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    postingActivityPostsOn($this->account, $this->today, [1, 2]);
    postingActivityPostsOn($other, $this->today, [1]);

    expect(postingActivity($this->workspace, [$this->account->id])['total'])->toBe(2)
        ->and(postingActivity($this->workspace)['total'])->toBe(3);
});

test('it applies the label filter', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $labelled = Post::factory()->forAccount($this->account)->published()->create();
    $labelled->labels()->attach($label);
    $plain = Post::factory()->forAccount($this->account)->published()->create();
    postingActivityPublication($this->account, $this->today->subDays(1)->setTime(12, 0), $labelled);
    postingActivityPublication($this->account, $this->today->subDays(2)->setTime(12, 0), $plain);

    $activity = postingActivity($this->workspace, filter: new PublicationFilter(null, [$label->id]));

    expect($activity['total'])->toBe(1)
        ->and($activity['current_streak'])->toBe(1);
});

test('it leaves out networks outside insights', function () {
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    postingActivityPostsOn($linkedIn, $this->today, [1]);

    expect(postingActivity($this->workspace)['total'])->toBe(0);
});

test('it returns zeros and no streaks without publications', function () {
    $activity = postingActivity($this->workspace);

    expect($activity['total'])->toBe(0)
        ->and($activity['max_count'])->toBe(0)
        ->and($activity['current_streak'])->toBe(0)
        ->and($activity['best_streak'])->toBe(0);
});
