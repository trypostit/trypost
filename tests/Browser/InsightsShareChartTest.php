<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Theme;
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

test('the followers card still exports when a channel photo cannot load with CORS', function () {
    [$user, $account] = insightsShareBrowserUser();
    Storage::put('social-accounts/avatar.png', file_get_contents(base_path('tests/fixtures/blue-logo.png')));
    $account->update(['avatar_url' => 'social-accounts/avatar.png']);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    $page->script(<<<'JS'
        window.__copied = [];
        Object.defineProperty(navigator, 'clipboard', {
            value: { write: async (items) => { for (const item of items) { window.__copied.push((await item.getType('image/png')).size); } } },
        });
    JS);
    openShareCard($page, '@share-followers');
    $page->click('@share-chart-copy');
    waitForText($page, '[data-sonner-toast]', trans('analytics.share.copied'));

    expect($page->script('window.__copied.length'))->toBe(1);
    $page->assertSeeIn('[data-sonner-toast]', trans('analytics.share.copied'))
        ->assertNoJavaScriptErrors();
});

test('the share card loads each channel photo before the dialog opens', function () {
    [$user, $account] = insightsShareBrowserUser();
    Storage::put('social-accounts/avatar.png', file_get_contents(base_path('tests/fixtures/blue-logo.png')));
    $account->update(['avatar_url' => 'social-accounts/avatar.png']);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForState($page, '@share-followers', "element.dataset.state === 'ready'");

    $page->assertAttribute('@share-followers', 'data-state', 'ready')
        ->assertMissing('@share-chart-dialog')
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

test('the summary shares as one card of its numbers', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-summary');

    $page->assertAttribute('@share-chart-preview', 'data-shape', 'tiles')
        ->assertVisible('@share-chart-download')
        ->assertVisible('@share-chart-copy')
        ->assertNoJavaScriptErrors();
});

test('the channel metrics card shares the chart as it is drawn', function () {
    [$user, $account] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $account));
    openShareCard($page, '@share-channel-metrics');

    $page->assertAttribute('@share-chart-preview', 'data-shape', 'combo')
        ->assertVisible('@share-chart-post')
        ->assertNoJavaScriptErrors();
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

test('the followers chart offers no share card while it has no follower data', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'provider_published_at' => now()->subDays(2)->setTime(10, 0),
    ]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-followers-empty');

    $page->assertMissing('@share-followers')
        ->assertNoJavaScriptErrors();
});

/** The text the composer opened with. */
function composerText(mixed $page): string
{
    waitFor($page, '@composer-base-content');

    return (string) $page->script('document.querySelector(\'[data-testid="composer-base-content"]\').value');
}

test('Create post opens the composer with the card’s number as the post text', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-followers');
    $page->click('@share-chart-post');

    expect(composerText($page))->toStartWith('Followers across our channels: 123 (');
    $page->assertNoJavaScriptErrors();
});

test('the channel chart’s post text leaves out a metric that has no value', function () {
    [$user, $account] = insightsShareBrowserUser();
    $quiet = SocialAccount::factory()->instagram()->create(['workspace_id' => $account->workspace_id, 'display_name' => 'Quiet Channel']);

    foreach ([4, 2] as $daysAgo) {
        AnalyticsPublication::factory()->create([
            'workspace_id' => $quiet->workspace_id,
            'social_account_id' => $quiet->id,
            'social_account_key' => $quiet->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'provider_published_at' => now()->subDays($daysAgo)->setTime(10, 0),
        ]);
    }

    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $quiet));
    openShareCard($page, '@share-channel-metrics');
    $page->click('@share-chart-post');
    $text = composerText($page);

    expect($text)->toContain('📈 Posts 2')
        ->not->toContain('Followers')
        ->not->toContain('—');
    $page->assertNoJavaScriptErrors();
});

test('downloading the share card saves a PNG named after the metric and today', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-followers');
    $page->script(<<<'JS'
        if (!window.__downloads) {
            window.__downloads = [];
            const createObjectURL = URL.createObjectURL.bind(URL);
            URL.createObjectURL = (file) => {
                window.__downloads.push(file.name);

                return createObjectURL(file);
            };
        }
    JS);
    $page->click('@share-chart-download');
    waitForCondition($page, 'window.__downloads.length > 0');

    expect($page->script('window.__downloads[0]'))->toMatch('/^trypost-followers-\d{4}-\d{2}-\d{2}\.png$/');
    $page->assertNoJavaScriptErrors();
});

test('closing the dialog while Create post uploads stops it, with no composer and no error', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-followers');
    $page->script(<<<'JS'
        window.__sent = 0;
        XMLHttpRequest.prototype.send = function () { window.__sent += 1; };
        XMLHttpRequest.prototype.abort = function () { window.__abortedAt = performance.now(); };
    JS);
    $page->click('@share-chart-post');
    waitForCondition($page, 'window.__sent > 0');
    $page->keys('@share-chart-dialog', 'Escape');
    waitForGone($page, '@share-chart-dialog');
    waitForCondition($page, 'window.__abortedAt !== undefined && performance.now() - window.__abortedAt > 500');

    expect($page->script('document.querySelectorAll("[data-sonner-toast]").length'))->toBe(0);
    $page->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a dark page still draws the share card in the light palette', function () {
    [$user] = insightsShareBrowserUser();
    $user->update(['theme' => Theme::Dark]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-followers');

    $palettes = $page->script(<<<'JS'
        (() => {
            const read = (light) => Array.from({ length: 10 }, (_, index) => {
                const probe = document.createElement('span');
                if (light) probe.dataset.lightTheme = '';
                probe.style.color = `var(--series-${index + 1})`;
                document.body.append(probe);
                const [r, g, b] = getComputedStyle(probe).color.match(/\d+/g).map(Number);
                probe.remove();
                return `${r},${g},${b}`;
            });
            const canvas = document.querySelector('[data-testid="share-chart-preview"]');
            const { data } = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height);
            const painted = new Set();
            for (let index = 0; index < data.length; index += 4) {
                painted.add(`${data[index]},${data[index + 1]},${data[index + 2]}`);
            }
            return {
                dark: document.documentElement.classList.contains('dark'),
                light: read(true).filter((color) => painted.has(color)).length,
                darkOnly: read(false).filter((color, index) => color !== read(true)[index] && painted.has(color)).length,
            };
        })()
    JS);

    expect($palettes)->toBe(['dark' => true, 'light' => 1, 'darkOnly' => 0]);
    $page->assertNoJavaScriptErrors();
});

test('a channel with neither name nor handle is shared under its network’s name', function () {
    [$user, $account] = insightsShareBrowserUser();
    $account->update(['display_name' => null, 'username' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.insights', $account));
    openShareCard($page, '@share-summary');
    $page->click('@share-chart-post');

    expect(composerText($page))->toStartWith('Our numbers on Instagram for ');
    $page->assertNoJavaScriptErrors();
});
