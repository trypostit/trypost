<?php

declare(strict_types=1);

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Models\Account;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
});

function analyticsAccount(bool $subscribed): SocialAccount
{
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);

    if ($subscribed) {
        subscribeAccount($account->workspace->account);
    }

    return $account;
}

test('follower collection skips accounts without app access', function () {
    $paying = analyticsAccount(true);
    analyticsAccount(false);
    Bus::fake([CollectAccountDailySnapshot::class]);

    $this->artisan('analytics:dispatch-account-daily')->assertSuccessful();

    Bus::assertDispatchedTimes(CollectAccountDailySnapshot::class, 1);
    Bus::assertDispatched(CollectAccountDailySnapshot::class, fn (CollectAccountDailySnapshot $job): bool => $job->socialAccountId === $paying->id);
});

test('publication metrics skip accounts without app access', function () {
    CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
    $paying = analyticsAccount(true);
    $lapsed = analyticsAccount(false);

    foreach ([$paying, $lapsed] as $account) {
        AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::X,
            'network' => Platform::X->network(),
            'platform_user_id' => $account->platform_user_id,
            'provider_published_at' => CarbonImmutable::now('UTC')->subDays(2),
        ]);
    }

    Bus::fake([CollectPublicationMetrics::class]);
    $this->artisan('analytics:dispatch-publication-metrics')->assertSuccessful();

    Bus::assertDispatchedTimes(CollectPublicationMetrics::class, 1);
});

test('publication discovery skips accounts without app access', function () {
    foreach ([analyticsAccount(true), analyticsAccount(false)] as $account) {
        foreach ([SyncCollector::PublicationBackfill, SyncCollector::PublicationDiscovery] as $collector) {
            AnalyticsSyncState::factory()->create([
                'social_account_id' => $account->id,
                'collector' => $collector,
                'status' => SyncStatus::Complete,
            ]);
        }
    }

    Bus::fake([DiscoverAccountPublications::class, BootstrapAccountAnalytics::class]);
    $this->artisan('analytics:dispatch-publication-discovery')->assertSuccessful();

    Bus::assertDispatchedTimes(DiscoverAccountPublications::class, 1);
});

test('a canceled subscription still in its paid period keeps analytics', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $account->workspace->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_grace',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'ends_at' => now()->addDays(5),
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();
});

test('self-hosted installs collect analytics without a subscription', function () {
    config(['trypost.self_hosted' => true]);

    expect(analyticsAccount(false)->hasAppAccess())->toBeTrue();
});

test('a published post is linked to insights but measured only with app access', function (bool $subscribed, int $reads) {
    $account = analyticsAccount($subscribed);
    Bus::fake([SyncTryPostPublication::class, CollectPublicationMetrics::class]);
    $post = Post::factory()
        ->forAccount($account, ContentType::defaultFor(Platform::X))
        ->published()
        ->create(['platform_post_id' => 'tweet-1', 'published_at' => now()]);

    app()->call([new SyncTryPostPublication(
        TryPostPublicationIdentity::fromAccount($account, app(ResolveAnalyticsAccountKey::class)->for($account)),
        $post->id,
    ), 'handle']);

    expect(AnalyticsPublication::query()->where('post_id', $post->id)->exists())->toBeTrue();
    Bus::assertDispatchedTimes(CollectPublicationMetrics::class, $reads);
})->with([
    'with app access' => [true, 1],
    'without app access' => [false, 0],
]);
