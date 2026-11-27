<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

test('workspace dashboard reads local follower facts and clamps the selected date range', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::Instagram]);
    foreach ([[$account, 12], [$foreign, 999]] as [$socialAccount, $followers]) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $socialAccount->workspace_id,
            'social_account_id' => $socialAccount->id,
            'social_account_key' => $socialAccount->id,
            'platform' => $socialAccount->platform,
            'network' => $socialAccount->platform->network(),
            'platform_user_id' => $socialAccount->platform_user_id,
            'date' => '2026-09-20',
            'followers_count' => $followers,
        ]);
    }
    Http::fake();

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-01-01', 'end' => '2026-12-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/insights/Index')
            ->where('report.range.start', '2026-09-20')
            ->where('report.range.end', '2026-09-20')
            ->where('report.summary.followers.value', 12)
            ->etc());

    Http::assertNothingSent();
});

test('dashboard rejects invalid dates and never renders an excluded account', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $linkedIn->id,
        'social_account_key' => $linkedIn->id,
        'platform' => Platform::LinkedIn,
        'network' => Platform::LinkedIn->network(),
        'platform_user_id' => $linkedIn->platform_user_id,
        'date' => '2026-09-20',
        'followers_count' => 100,
    ]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => 'nonsense', 'end' => '2026-09-20']))
        ->assertSessionHasErrors('start');

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-20', 'end' => '2026-09-10']))
        ->assertSessionHasErrors('end');

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '']))
        ->assertSessionHasErrors('start');

    $this->actingAs($user)
        ->get(route('app.insights'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/insights/Index')
            ->where('report.bounds.min', null)
            ->where('report.summary.followers.value', null)
            ->etc());
});

test('dashboard keeps the default range inside available data when only one date is selected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);

    foreach (['2026-09-01', '2026-09-20'] as $date) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => $date,
        ]);
    }

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.range.start', '2026-09-20')
            ->where('report.range.end', '2026-09-20')
            ->etc());

    $this->actingAs($user)
        ->get(route('app.insights', ['end' => '2026-08-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.range.start', '2026-09-01')
            ->where('report.range.end', '2026-09-01')
            ->etc());
});

test('empty dashboard ignores an unbounded requested date range', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '1000-01-01', 'end' => '9999-12-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.bounds.min', null)
            ->where('report.range.start', now('UTC')->startOfDay()->subDays(29)->toDateString())
            ->where('report.range.end', now('UTC')->startOfDay()->toDateString())
            ->etc());
});

test('a workspace without connected channels shows no past analytics', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => null,
        'social_account_key' => (string) Str::uuid(),
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => 'disconnected',
        'date' => '2026-09-20',
        'followers_count' => 42,
    ]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-01-01', 'end' => '2026-12-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('app/insights/Index')
            ->where('channelOptions', [])
            ->where('report.bounds.min', null)
            ->where('report.followers.accounts', [])
            ->etc());
});

test('the dashboard defers the posting activity of the selected channels', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $picked = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $other = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);

    foreach ([[$picked, 2], [$other, 1]] as [$account, $posts]) {
        foreach (range(1, $posts) as $day) {
            $publishedAt = now('UTC')->subDays($day)->setTime(12, 0);
            $publication = AnalyticsPublication::factory()->create([
                'workspace_id' => $workspace->id,
                'social_account_id' => $account->id,
                'social_account_key' => $account->id,
                'network' => $account->platform->network(),
                'platform_user_id' => $account->platform_user_id,
                'platform' => $account->platform,
                'provider_published_at' => $publishedAt,
            ]);
            AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => $publishedAt->toDateString()]);
        }
    }

    $this->actingAs($user)
        ->get(route('app.insights', ['channels' => [$picked->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('postingActivity')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('postingActivity.total', 2)
                ->where('postingActivity.current_streak', 2)
                ->has('postingActivity.days', 365)));

    $this->actingAs($user)
        ->get(route('app.insights'))
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('postingActivity.total', 3)));
});
