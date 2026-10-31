<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
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

/** The photo the test channels get: a picture the test server serves, unlike the faked storage disk. */
const INSIGHTS_SHARE_AVATAR = '/images/accounts/linkedin.png';

/** Gives the channel a photo the page can load, on the follower readings Insights reads it from. */
function insightsShareAvatar(SocialAccount $account): void
{
    AnalyticsAccountDailySnapshot::query()
        ->where('social_account_id', $account->id)
        ->update(['account_avatar_url' => INSIGHTS_SHARE_AVATAR]);
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
        ->assertAttribute('@share-chart-color-green', 'aria-checked', 'true');
    waitForCondition($page, <<<'JS'
        (() => {
            const canvas = document.querySelector('[data-testid="share-chart-preview"]');
            const [r, g, b] = canvas.getContext('2d').getImageData(2, Math.round(canvas.height / 2), 1, 1).data;

            return `${r},${g},${b}` === '217,241,209';
        })()
    JS);
    $page->click('@share-chart-post');

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

test('the followers card copies with a channel photo drawn on it', function () {
    [$user, $account] = insightsShareBrowserUser();
    insightsShareAvatar($account);
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

test('the share card loads each channel photo before the dialog opens and draws it fetched with CORS', function () {
    [$user, $account] = insightsShareBrowserUser();
    insightsShareAvatar($account);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitForState($page, '@share-followers', "element.dataset.state === 'ready'");

    $page->assertAttribute('@share-followers', 'data-state', 'ready')
        ->assertMissing('@share-chart-dialog');

    recordCanvasDrawing($page);
    openShareCard($page, '@share-followers');

    expect($page->script('window.__images.some((source) => source.includes("/images/accounts/linkedin.png") && source.includes("cors=1"))'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
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
    insightsShareAvatar($account);

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

/** Adds channels with one follower reading each, named by `$name`, so the per channel card fills up. */
function insightsShareChannels(SocialAccount $account, int $count, Closure $name): void
{
    foreach (range(1, $count) as $index) {
        $channel = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $account->workspace_id,
            'display_name' => $name($index),
        ]);
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $channel->id,
            'social_account_key' => $channel->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $channel->platform_user_id,
            'date' => now('UTC')->subDays(3)->toDateString(),
            'followers_count' => 1000 + $index,
            'account_display_name' => $name($index),
        ]);
    }
}

test('a long channel name is cut between whole characters, never inside an emoji', function () {
    [$user, $account] = insightsShareBrowserUser();
    $name = str_repeat('Team 🚀 ', 20);
    insightsShareChannels($account, 11, fn (int $index): string => $index === 1 ? $name : sprintf('Channel %02d', $index));
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    recordCanvasDrawing($page);
    openShareCard($page, '@share-followers');

    $cut = $page->script(<<<JS
        (() => {
            const full = '{$name}';
            const label = window.__drawn.map((entry) => entry.text).find((text) => text.endsWith('…') && full.startsWith(text.slice(0, -1)));
            const kept = label.slice(0, -1);
            const boundaries = new Set(Array.from(new Intl.Segmenter().segment(full), (piece) => piece.index));

            return { cut: kept.length < full.length, wholeCharacters: boundaries.has(kept.length), wellFormed: label.isWellFormed() };
        })()
    JS);

    expect($cut)->toBe(['cut' => true, 'wholeCharacters' => true, 'wellFormed' => true]);
    $page->assertNoJavaScriptErrors();
});

test('in a right-to-left language every text of the card runs right to left, preview and file alike', function () {
    [$user] = insightsShareBrowserUser();
    $user->update(['locale' => Locale::Arabic]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    recordCanvasDrawing($page);
    openShareCard($page, '@share-followers');
    $preview = $page->script('window.__drawn.length');
    $page->click('@share-chart-download');
    waitForCondition($page, "window.__drawn.length > {$preview}");

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('[...new Set(window.__drawn.map((entry) => entry.direction))]'))->toBe(['rtl'])
        ->and($page->script('window.__drawn.every((entry) => ["left", "right", "center"].includes(entry.align))'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the per channel card draws the twelve channels with the most followers', function () {
    [$user, $account] = insightsShareBrowserUser();

    insightsShareChannels($account, 12, fn (int $index): string => sprintf('Channel %02d', $index));

    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    recordCanvasDrawing($page);
    openShareCard($page, '@share-followers');

    $names = $page->script('[...new Set(window.__drawn.map((entry) => entry.text).filter((text) => /^Channel \\d{2}$/.test(text)))].sort()');

    expect($names)->toHaveCount(12)
        ->and($page->script('window.__drawn.some((entry) => entry.text === "123")'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('the stacked posts card keeps its week labels apart over a long range', function () {
    [$user, $account] = insightsShareBrowserUser();

    foreach ([89, 60, 30, 1] as $daysAgo) {
        AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'provider_published_at' => now()->subDays($daysAgo)->setTime(10, 0),
        ]);
    }

    $this->actingAs($user);

    $page = visit(route('app.insights', ['start' => now()->subDays(89)->toDateString(), 'end' => now()->subDay()->toDateString()]));
    waitFor($page, '@posts-stacked');
    $page->click('@posts-stacked');
    recordCanvasDrawing($page);
    openShareCard($page, '@share-posts');

    $dates = $page->script(<<<'JS'
        (() => {
            const centred = window.__drawn.filter((entry) => entry.align === 'center' && entry.text.includes('–'));
            const unique = [...new Map(centred.map((entry) => [`${entry.text}|${entry.x}`, entry])).values()].sort((a, b) => a.x - b.x);
            const overlaps = unique.slice(1).filter((entry, index) => entry.x - entry.width / 2 < unique[index].x + unique[index].width / 2).length;

            return { shown: unique.length, overlaps };
        })()
    JS);

    expect($dates['shown'])->toBeGreaterThan(1)
        ->and($dates['overlaps'])->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('a share of some of the channels says how many of them it counts', function () {
    [$user, $account] = insightsShareBrowserUser();
    insightsShareChannels($account, 1, fn (): string => 'Other Channel');
    $this->actingAs($user);

    $page = visit(route('app.insights', ['channels' => [$account->id]]));
    openShareCard($page, '@share-followers');
    $page->click('@share-chart-post');

    expect(composerText($page))->toEndWith('· 1 of 2 channels) 📈');
    $page->assertNoJavaScriptErrors();
});

test('a browser that cannot draw the card stops loading the preview and is told so', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    waitFor($page, '@share-followers');
    $page->script('(() => { CanvasRenderingContext2D.prototype.roundRect = undefined; })()');
    $page->click('@share-followers');
    waitForText($page, '[data-sonner-toast]', 'Couldn');

    $page->assertAttribute('@share-chart-preview', 'data-state', 'ready');
    expect($page->script('document.querySelectorAll("[data-sonner-toast]").length'))->toBe(1);
});

test('a Create post stopped by closing the dialog never unblocks the next one', function () {
    [$user] = insightsShareBrowserUser();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openShareCard($page, '@share-followers');
    $page->script(<<<'JS'
        (() => {
            window.__encoded = 0;
            const toBlob = HTMLCanvasElement.prototype.toBlob;
            HTMLCanvasElement.prototype.toBlob = function (callback, ...rest) {
                toBlob.call(this, (blob) => window.setTimeout(() => { window.__encoded += 1; callback(blob); }, 800), ...rest);
            };
            XMLHttpRequest.prototype.send = function () {};
        })()
    JS);
    $page->click('@share-chart-post');
    $page->keys('@share-chart-dialog', 'Escape');
    waitForGone($page, '@share-chart-dialog');
    openShareCard($page, '@share-followers');
    $page->click('@share-chart-post');
    waitForCondition($page, 'window.__encoded >= 1');

    $page->assertAttribute('@share-chart-post', 'disabled', '')
        ->assertNoJavaScriptErrors();
});
