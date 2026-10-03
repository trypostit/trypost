<?php

declare(strict_types=1);

use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

function analyticsPresetUserWithSnapshot(string $date): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => $date,
        'followers_count' => 10,
    ]);

    return $user;
}

test('7d covers the last seven days including today', function () {
    $this->travelTo('2026-09-30 12:00 UTC');

    expect((new ResolveAnalyticsRangePreset)->handle('7d', null, null, 'UTC'))
        ->toBe(['start' => '2026-09-24', 'end' => '2026-09-30']);
});

test('month presets follow the user calendar day rather than the UTC day', function () {
    $this->travelTo('2026-10-01 02:30 UTC');
    $action = new ResolveAnalyticsRangePreset;

    expect($action->handle('mtd', null, null, 'America/Sao_Paulo'))
        ->toBe(['start' => '2026-09-01', 'end' => '2026-09-30'])
        ->and($action->handle('last_month', null, null, 'America/Sao_Paulo'))
        ->toBe(['start' => '2026-08-01', 'end' => '2026-08-31']);
});

test('custom returns the given dates', function () {
    expect((new ResolveAnalyticsRangePreset)->handle('custom', '2026-01-05', '2026-02-10', 'UTC'))
        ->toBe(['start' => '2026-01-05', 'end' => '2026-02-10']);
});

test('an unknown preset is rejected', function () {
    $user = analyticsPresetUserWithSnapshot('2026-09-20');

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => 'forever']))
        ->assertSessionHasErrors('range');
});

test('custom requires both dates', function () {
    $user = analyticsPresetUserWithSnapshot('2026-09-20');

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => 'custom']))
        ->assertSessionHasErrors(['start', 'end']);
});

test('a preset resolves in the user time zone and is not clamped to the available data', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $user = analyticsPresetUserWithSnapshot('2026-09-28');

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => '7d']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.range', '7d')
            ->where('report.range.start', '2026-09-24')
            ->where('report.range.end', '2026-09-30')
            ->where('report.filters.start', '2026-09-24')
            ->where('report.filters.end', '2026-09-30')
            ->where('report.bounds.max', '2026-09-28')
            ->etc());
});

test('a custom range is still clamped to the available data', function () {
    $user = analyticsPresetUserWithSnapshot('2026-09-20');

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.range', 'custom')
            ->where('report.range.start', '2026-09-20')
            ->where('report.range.end', '2026-09-20')
            ->etc());
});

test('no range resolves through the default preset in the user time zone', function () {
    $this->travelTo('2026-10-01 02:00 UTC');
    $user = analyticsPresetUserWithSnapshot('2026-09-20');
    $user->update(['timezone' => 'America/Sao_Paulo']);

    $this->actingAs($user)
        ->get(route('app.insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.range', ResolveAnalyticsRangePreset::DEFAULT)
            ->where('report.range.start', '2026-09-01')
            ->where('report.range.end', '2026-09-30')
            ->etc());
});

test('empty dates with a preset range are ignored instead of rejected', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $user = analyticsPresetUserWithSnapshot('2026-09-20');

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => '7d', 'start' => '', 'end' => '']))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.range', '7d')
            ->where('report.range.start', '2026-09-24')
            ->where('report.range.end', '2026-09-30')
            ->etc());
});
