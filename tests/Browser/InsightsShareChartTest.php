<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
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

/** Opens the share dialog from `$button` and waits for its preview to be drawn. */
function openShareCard(mixed $page, string $button): void
{
    waitFor($page, $button);
    $page->click($button);
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->assertAttribute('@share-chart-preview', 'data-state', 'ready');
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
    openShareCard($page, '@share-followers');

    $page->click('@share-chart-color-green')
        ->assertAttribute('@share-chart-color-green', 'aria-checked', 'true')
        ->click('@share-chart-post');

    waitFor($page, '@post-composer-dialog');
    waitForGone($page, '@share-chart-dialog');

    expect(Media::query()->where('original_filename', 'like', 'trypost-followers-%')->exists())->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('copying the share card writes a PNG to the clipboard', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-summary');
    $page->script(<<<'JS'
        window.__copied = [];
        Object.defineProperty(navigator, 'clipboard', {
            value: { write: async (items) => { window.__copied.push(...items.flatMap((item) => item.types)); } },
        });
    JS);
    openShareCard($page, '@share-summary');
    $page->click('@share-chart-copy');
    waitForText($page, '[data-sonner-toast]', trans('analytics.share.copied'));

    expect($page->script('window.__copied'))->toBe(['image/png']);
    $page->assertSeeIn('[data-sonner-toast]', trans('analytics.share.copied'))
        ->assertNoJavaScriptErrors();
});

test('the followers card draws each channel with its avatar and still exports', function () {
    [$user, $account] = insightsShareBrowserUser();
    Storage::put('social-accounts/avatar.png', file_get_contents(base_path('tests/fixtures/blue-logo.png')));
    $account->update(['avatar_url' => 'social-accounts/avatar.png']);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    $page->script(<<<'JS'
        performance.clearResourceTimings();
        performance.setResourceTimingBufferSize(1000);
        window.__copied = [];
        Object.defineProperty(navigator, 'clipboard', {
            value: { write: async (items) => { for (const item of items) { window.__copied.push((await item.getType('image/png')).size); } } },
        });
    JS);
    openShareCard($page, '@share-followers');
    $page->click('@share-chart-copy');
    waitForText($page, '[data-sonner-toast]', trans('analytics.share.copied'));

    $avatarPath = route('app.channels.avatar', $account, absolute: false);

    expect($page->script('window.__copied.length'))->toBe(1)
        ->and($page->script("performance.getEntriesByType('resource').some((entry) => new URL(entry.name).pathname === '{$avatarPath}')"))->toBeTrue();
    $page->assertSeeIn('[data-sonner-toast]', trans('analytics.share.copied'))
        ->assertNoJavaScriptErrors();
});

test('a browser that cannot copy images is told to download the card instead', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-summary');
    $page->script('delete window.ClipboardItem');
    openShareCard($page, '@share-summary');
    $page->click('@share-chart-copy');
    waitForText($page, '[data-sonner-toast]', trans('analytics.share.copy_failed'));

    $page->assertSeeIn('[data-sonner-toast]', trans('analytics.share.copy_failed'))
        ->assertNoJavaScriptErrors();
});

test('the summary shares as one card of its numbers, with no metric to pick', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-summary');

    $page->assertAttribute('@share-chart-preview', 'data-shape', 'tiles')
        ->assertMissing('@share-chart-metric')
        ->assertVisible('@share-chart-download')
        ->assertVisible('@share-chart-copy')
        ->assertNoJavaScriptErrors();
});

test('the channel metrics card shares the channel’s series', function () {
    [$user, $account] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $account));
    openShareCard($page, '@share-channel-metrics');

    $page->assertVisible('@share-chart-post')->assertNoJavaScriptErrors();
});

test('the followers share card follows the chart mode the user picked', function (string $mode, string $shape) {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, "@followers-{$mode}");
    $page->click("@followers-{$mode}");
    openShareCard($page, '@share-followers');

    $page->assertAttribute('@share-chart-preview', 'data-shape', $shape)
        ->assertNoJavaScriptErrors();
})->with([
    'bar' => ['bar', 'rows'],
    'line' => ['line', 'lines'],
    'growth' => ['growth', 'rows'],
]);

test('the posts share card follows the chart mode the user picked', function (string $mode, string $shape) {
    [$user, $account] = insightsShareBrowserUser();
    Storage::put('social-accounts/avatar.png', file_get_contents(base_path('tests/fixtures/blue-logo.png')));
    $account->update(['avatar_url' => 'social-accounts/avatar.png']);

    foreach ([12, 9, 9, 4] as $daysAgo) {
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'provider_published_at' => now()->subDays($daysAgo)->setTime(10, 0),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id]);
    }

    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, "@posts-{$mode}");
    $page->click("@posts-{$mode}");
    openShareCard($page, '@share-posts');

    $page->assertAttribute('@share-chart-preview', 'data-shape', $shape)
        ->assertNoJavaScriptErrors();
})->with([
    'bar' => ['bar', 'rows'],
    'stacked' => ['stacked', 'stacked'],
]);
