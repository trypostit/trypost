<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function waitForPostingGoalTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function postingGoalSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'posting_goal' => 3]);

    return [$user->fresh(), $channel];
}

function postPopupResult(mixed $page, SocialAccount $channel, bool $created): void
{
    $flag = $created ? 'true' : 'false';
    $page->script(<<<JS
        window.postMessage({
            type: 'social-oauth-callback',
            success: true,
            message: '',
            platform: 'linkedin',
            account_id: '{$channel->id}',
            created: {$flag},
        }, window.location.origin);
    JS);
}

test('a newly created channel opens the goal flow and saves the chosen goal', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-flow');

    $page->click('@goal-option-5')->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-recommended');

    expect($page->script('document.querySelectorAll(\'[data-testid="goal-recommended-row"]\').length'))->toBe(5);
    expect($channel->fresh()->posting_goal)->toBe(5)
        ->and($channel->fresh()->posting_schedule->slotCount())->toBe(5);

    $page->click('@goal-done');
    $page->assertMissing('@goal-flow')->assertNoJavaScriptErrors();
});

test('a custom goal uses the stepper', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-option-custom');
    $page->click('@goal-option-custom');
    waitForPostingGoalTestId($page, 'goal-custom-increase');
    $page->click('@goal-custom-increase')->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-recommended');

    expect($channel->fresh()->posting_goal)->toBe(7);
    $page->assertNoJavaScriptErrors();
});

test('customize goes to the channel settings page', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-next');
    $page->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-customize');
    $page->click('@goal-customize');
    waitForPostingGoalTestId($page, 'channel-settings-page');

    $page->assertVisible('@channel-settings-page')->assertNoJavaScriptErrors();
});

test('a reconnect does not open the goal flow', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, false);
    waitForPostingGoalTestId($page, 'goal-flow');

    $page->assertMissing('@goal-flow')->assertNoJavaScriptErrors();
});

function postingGoalPollFor(mixed $page, string $condition, int $attempts = 120): bool
{
    return (bool) $page->script(<<<JS
        (async () => {
            for (let i = 0; i < {$attempts}; i++) {
                if ({$condition}) return true;
                await new Promise((r) => setTimeout(r, 50));
            }
            return false;
        })();
    JS);
}

test('the goal step matches the wide card layout with right-side radios', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-option-3');

    $layout = $page->script(<<<'JS'
        (() => {
            const rect = (el) => el.getBoundingClientRect();
            const dialog = document.querySelector('[data-testid="connect-channel-dialog"]');
            const row = document.querySelector('[data-testid="goal-option-3"]');
            const other = document.querySelector('[data-testid="goal-option-1"]');
            const badge = row.querySelector('[data-testid="goal-option-badge"]');
            const radio = row.querySelector('[data-testid="goal-option-radio"]');
            const footer = document.querySelector('[data-testid="goal-footer"]');
            const help = rect(document.querySelector('[data-testid="goal-help"]'));
            const next = rect(document.querySelector('[data-testid="goal-next"]'));

            return {
                dialogWidth: dialog.offsetWidth,
                rowHeight: Math.round(rect(row).height),
                radioRightOfBadge: rect(radio).left > rect(badge).right,
                radioNearRightEdge: rect(row).right - rect(radio).right < 40,
                selectedBackground: getComputedStyle(row).backgroundColor,
                selectedBorderDiffers: getComputedStyle(row).borderColor !== getComputedStyle(other).borderColor,
                footerHasTopBorder: getComputedStyle(footer).borderTopWidth === '1px',
                helpBeforeNext: help.right < next.left,
                labelText: row.innerText.replace(/\s+/g, ' ').trim(),
            };
        })();
    JS);

    expect($layout['dialogWidth'])->toBe(800)
        ->and($layout['rowHeight'])->toBeGreaterThanOrEqual(62)
        ->and($layout['radioRightOfBadge'])->toBeTrue()
        ->and($layout['radioNearRightEdge'])->toBeTrue()
        ->and($layout['selectedBackground'])->toBe('rgba(0, 0, 0, 0)')
        ->and($layout['selectedBorderDiffers'])->toBeTrue()
        ->and($layout['footerHasTopBorder'])->toBeTrue()
        ->and($layout['helpBeforeNext'])->toBeTrue()
        ->and($layout['labelText'])->toBe('3x Build a presence · 3 times/week');

    $page->assertNoJavaScriptErrors();
});

test('the recommended-time help opens above its trigger and links to the docs', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-help');
    $page->click('@goal-help');
    waitForPostingGoalTestId($page, 'goal-help-popover');

    $popover = $page->script(<<<'JS'
        (() => {
            const trigger = document.querySelector('[data-testid="goal-help"]').getBoundingClientRect();
            const card = document.querySelector('[data-testid="goal-help-popover"]').getBoundingClientRect();
            const link = document.querySelector('[data-testid="goal-help-learn-more"]');

            return {
                above: card.bottom <= trigger.top,
                href: link.getAttribute('href'),
                target: link.getAttribute('target'),
                rel: link.getAttribute('rel'),
                actionsOrder: link.getBoundingClientRect().right
                    < document.querySelector('[data-testid="goal-help-close"]').getBoundingClientRect().left,
            };
        })();
    JS);

    expect($popover['above'])->toBeTrue()
        ->and($popover['href'])->toBe('https://docs.trypost.it')
        ->and($popover['target'])->toBe('_blank')
        ->and($popover['rel'])->toContain('noopener')
        ->and($popover['actionsOrder'])->toBeTrue();

    $page->click('@goal-help-close');

    expect(postingGoalPollFor($page, '!document.querySelector(\'[data-testid="goal-help-popover"]\')'))->toBeTrue();
    $page->assertVisible('@goal-flow')->assertNoJavaScriptErrors();
});

test('the recommended step bolds the times, orders its footer and shows no success toast', function () {
    [$user, $channel] = postingGoalSetup();
    $user->update(['time_format' => '24h']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-next');
    $page->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-recommended');

    $state = $page->script(<<<'JS'
        (() => {
            const rows = [...document.querySelectorAll('[data-testid="goal-recommended-row"]')];
            const left = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().left;

            return {
                strongPerRow: rows.map((row) => row.querySelectorAll('strong').length),
                strongTimes: rows.flatMap((row) => [...row.querySelectorAll('strong')].map((el) => el.textContent.trim())),
                footerOrder: left('goal-change') < left('goal-customize') && left('goal-customize') < left('goal-done'),
                learnMore: document.querySelector('[data-testid="goal-learn-more"]').getAttribute('href'),
                toasts: [...document.querySelectorAll('[data-sonner-toast]')].map((toast) => toast.innerText.trim()),
            };
        })();
    JS);

    expect($state['strongPerRow'])->toBe([2, 2, 2])
        ->and($state['strongTimes'])->each->toMatch('/^\d{2}:00$/')
        ->and($state['footerOrder'])->toBeTrue()
        ->and($state['learnMore'])->toBe('https://docs.trypost.it')
        ->and($state['toasts'])->toBe([]);

    $page->click('@goal-change');
    waitForPostingGoalTestId($page, 'goal-flow');
    $page->assertVisible('@goal-option-3')->assertNoJavaScriptErrors();
});

test('connecting a channel fires a confetti burst that cleans up after itself', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    postPopupResult($page, $channel, true);

    expect(postingGoalPollFor($page, 'document.querySelector(\'[data-testid="confetti-canvas"]\')'))->toBeTrue()
        ->and(postingGoalPollFor($page, '!document.querySelector(\'[data-testid="confetti-canvas"]\')'))->toBeTrue();

    $page->assertVisible('@goal-flow')->assertNoJavaScriptErrors();
});

test('confetti stays off when the user prefers reduced motion', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");
    $page->script(<<<'JS'
        (() => {
            const original = window.matchMedia.bind(window);
            window.matchMedia = (query) => query.includes('prefers-reduced-motion')
                ? { matches: true, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }
                : original(query);
        })();
    JS);
    postPopupResult($page, $channel, true);
    waitForPostingGoalTestId($page, 'goal-flow');

    expect(postingGoalPollFor($page, 'document.querySelector(\'[data-testid="confetti-canvas"]\')', 20))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

function fakeBlueskyIdentity(string $did): void
{
    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'did' => $did,
            'handle' => 'goal.bsky.social',
            'accessJwt' => 'access-token',
            'refreshJwt' => 'refresh-token',
        ]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response([
            'did' => $did,
            'handle' => 'goal.bsky.social',
            'displayName' => 'Goal Bluesky',
        ]),
    ]);
}

function connectBlueskyInPopup(mixed $page, string $url): bool
{
    return (bool) $page->script(<<<JS
        (async () => {
            const wait = (ms) => new Promise((r) => setTimeout(r, ms));
            const popup = window.open('{$url}', 'oauth-popup', 'width=600,height=700');
            window.__connectPopup = popup;
            window.__popupRenderedText = false;

            let identifier = null;
            for (let i = 0; i < 200 && !identifier; i++) {
                await wait(50);
                identifier = popup.document?.querySelector('[data-testid="bluesky-identifier"]') ?? null;
            }
            if (!identifier) return false;

            new popup.MutationObserver(() => {
                const callback = popup.document.querySelector('[data-testid="popup-callback"]');
                if (callback && callback.textContent.trim() !== '') {
                    window.__popupRenderedText = true;
                }
            }).observe(popup.document.body, { childList: true, subtree: true, characterData: true });

            const fill = (selector, value) => {
                const input = popup.document.querySelector(selector);
                input.value = value;
                input.dispatchEvent(new popup.Event('input', { bubbles: true }));
            };
            fill('[data-testid="bluesky-identifier"]', 'goal.bsky.social');
            fill('[data-testid="bluesky-password"]', 'xxxx-xxxx-xxxx-xxxx');
            await wait(100);
            popup.document.querySelector('[data-testid="bluesky-submit"]').click();

            return true;
        })();
    JS);
}

test('a successful popup connect closes the popup straight into the goal flow', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);
    fakeBlueskyIdentity('did:plc:goal-new');

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");

    expect(connectBlueskyInPopup($page, route('app.social.bluesky.connect')))->toBeTrue()
        ->and(postingGoalPollFor($page, 'window.__connectPopup.closed', 200))->toBeTrue();

    waitForPostingGoalTestId($page, 'goal-flow');

    expect($page->script('window.__popupRenderedText'))->toBeFalse()
        ->and($user->currentWorkspace->socialAccounts()->where('platform_user_id', 'did:plc:goal-new')->exists())->toBeTrue();

    $page->assertVisible('@goal-flow')
        ->assertDontSee('Account connected!')
        ->assertNoJavaScriptErrors();
});

test('a popup reconnect closes the popup without opening the goal flow', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);
    $bluesky = SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $channel->workspace_id,
        'platform_user_id' => 'did:plc:goal-existing',
    ]);
    fakeBlueskyIdentity('did:plc:goal-existing');

    $page = visit(route('app.posts.index'));
    waitForPostingGoalTestId($page, "sidebar-channel-{$channel->id}");

    expect(connectBlueskyInPopup($page, route('app.social.bluesky.connect', ['reconnect' => $bluesky->id])))->toBeTrue()
        ->and(postingGoalPollFor($page, 'window.__connectPopup.closed', 200))->toBeTrue()
        ->and(postingGoalPollFor($page, 'document.querySelector(\'[data-testid="goal-flow"]\')', 20))->toBeFalse()
        ->and($page->script('window.__popupRenderedText'))->toBeFalse()
        ->and($bluesky->fresh()->access_token)->toBe('access-token');

    $page->assertNoJavaScriptErrors();
});

test('a connect callback without an opener lands on the channels page', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);
    fakeBlueskyIdentity('did:plc:goal-tab');

    $page = visit(route('app.social.bluesky.connect'));
    waitForPostingGoalTestId($page, 'bluesky-identifier');
    $page->type('@bluesky-identifier', 'goal.bsky.social')
        ->type('@bluesky-password', 'xxxx-xxxx-xxxx-xxxx')
        ->click('@bluesky-submit');
    waitForPostingGoalTestId($page, "channel-list-row-{$channel->id}");

    $page->assertVisible('@channels-connect')
        ->assertMissing('@popup-callback')
        ->assertNoJavaScriptErrors();
});
