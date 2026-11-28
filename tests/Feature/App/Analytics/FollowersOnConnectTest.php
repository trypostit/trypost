<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake([BootstrapAccountAnalytics::class]);
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['followers_count' => 3566]),
        config('trypost.platforms.mastodon.default_instance').'/api/v1/accounts/*' => Http::response(['followers_count' => 42]),
    ]);

    $this->user = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function followersOnConnectAccount(Workspace $workspace, Platform $platform, array $attributes = []): SocialAccount
{
    $factory = $platform === Platform::Mastodon ? SocialAccount::factory()->mastodon() : SocialAccount::factory()->instagram();

    return $factory->create([
        'workspace_id' => $workspace->id,
        'meta' => $platform === Platform::Mastodon ? ['instance' => config('trypost.platforms.mastodon.default_instance')] : [],
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function followersOnConnectReport(User $user, Workspace $workspace): array
{
    ['selection' => $selection, 'clamped' => $clamped] = app(ResolveAnalyticsRangePreset::class)->selection([], $user->timezone);

    return app(BuildWorkspaceAnalyticsReport::class)->forSelection($workspace, $selection, clampToBounds: $clamped);
}

dataset('follower platforms', [
    'instagram' => [Platform::Instagram, 3566],
    'mastodon' => [Platform::Mastodon, 42],
]);

test('connecting an account late in the user evening shows its followers right away', function (Platform $platform, int $followers) {
    $this->travelTo('2026-10-02 01:30:00 UTC');

    $account = followersOnConnectAccount($this->workspace, $platform);

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $account->id)->sole())
        ->followers_count->toBe($followers)
        ->date->toDateString()->toBe('2026-10-02');

    $report = followersOnConnectReport($this->user, $this->workspace);

    expect(data_get($report, 'range.end'))->toBe('2026-10-01')
        ->and(data_get($report, 'summary.followers.value'))->toBe($followers)
        ->and(data_get($report, 'followers.total'))->toBe($followers)
        ->and(data_get($report, 'followers.accounts.0.value'))->toBe($followers)
        ->and(data_get(collect(data_get($report, 'followers.series'))->last(), "accounts.{$account->id}"))->toBe($followers);
})->with('follower platforms');

test('reconnecting an account late in the user evening shows its followers right away', function (Platform $platform, int $followers) {
    $this->travelTo('2026-10-02 01:30:00 UTC');

    $account = followersOnConnectAccount($this->workspace, $platform, ['status' => Status::Disconnected]);

    expect(AnalyticsAccountDailySnapshot::query()->where('social_account_id', $account->id)->exists())->toBeFalse();

    $account->update(['status' => Status::Connected]);

    expect(data_get(followersOnConnectReport($this->user, $this->workspace), 'summary.followers.value'))->toBe($followers);
})->with('follower platforms');

test('a range that ended before today does not take a later observation', function () {
    $this->travelTo('2026-10-02 01:30:00 UTC');
    followersOnConnectAccount($this->workspace, Platform::Instagram);

    $report = app(BuildWorkspaceAnalyticsReport::class)->forSelection(
        $this->workspace,
        ['start' => '2026-09-01', 'end' => '2026-09-30'],
        clampToBounds: false,
    );

    expect(data_get($report, 'summary.followers.value'))->toBeNull()
        ->and(data_get($report, 'followers.accounts'))->toBe([]);
});
