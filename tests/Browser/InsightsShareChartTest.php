<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\Media;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

function insightsShareBrowserUser(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id, 'name' => 'Acme Studio']);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);

    foreach ([5, 3] as $daysAgo) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => now('UTC')->subDays($daysAgo)->toDateString(),
            'followers_count' => 120 + $daysAgo,
        ]);
    }

    return [$user->fresh(), $account];
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Storage::fake();
    Http::fake(['*' => Http::response('', Response::HTTP_NOT_FOUND)]);
});

test('the followers chart opens a share card in a TryPost tint that turns into a post with the image', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    $page->click('@share-followers');
    waitFor($page, '@share-chart-preview');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->assertAttribute('@share-chart-preview', 'data-state', 'ready');

    $page->click('@share-chart-color-green')
        ->assertAttribute('@share-chart-color-green', 'aria-checked', 'true')
        ->click('@share-chart-post');

    waitFor($page, '@post-composer-dialog');
    waitForGone($page, '@share-chart-dialog');

    expect(Media::query()->where('original_filename', 'like', 'trypost-followers-%')->exists())->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the summary share card holds every number of the Summary', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-summary');
    $page->click('@share-summary');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->assertAttribute('@share-chart-preview', 'data-state', 'ready');

    $page->assertMissing('@share-chart-metric')
        ->assertVisible('@share-chart-download')
        ->assertVisible('@share-chart-copy')
        ->assertNoJavaScriptErrors();
});

test('the channel metrics card shares the channel’s series', function () {
    [$user, $account] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $account));
    waitFor($page, '@share-channel-metrics');
    $page->click('@share-channel-metrics');
    waitFor($page, '@share-chart-dialog');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->assertAttribute('@share-chart-preview', 'data-state', 'ready');

    $page->assertVisible('@share-chart-post')->assertNoJavaScriptErrors();
});
