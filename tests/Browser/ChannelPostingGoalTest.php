<?php

declare(strict_types=1);

use App\Enums\User\Locale;
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

    waitForWebFonts($page);
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

/**
 * Connect a new Bluesky channel the way a user does: the form, the confirmation
 * page, then "Finish connection" back on the posts page.
 *
 * @return array{0: mixed, 1: SocialAccount}
 */
function connectPostingGoalChannel(string $did = 'did:plc:goal-new', array $query = [], ?Closure $prepare = null): array
{
    fakeBlueskyIdentity($did);
    $postsPath = parse_url(route('app.posts.index'), PHP_URL_PATH);

    $page = visit(route('app.social.bluesky.connect', ['return_to' => $postsPath, ...$query]))->resize(1440, 900);
    waitForPostingGoalTestId($page, 'bluesky-identifier');

    if ($prepare !== null) {
        $prepare($page);
    }

    $page->type('@bluesky-identifier', 'goal.bsky.social')
        ->type('@bluesky-password', 'xxxx-xxxx-xxxx-xxxx')
        ->click('@bluesky-submit');
    waitForPostingGoalTestId($page, 'connect-finish');
    $page->click('@connect-finish');

    expect(postingGoalPollFor($page, '/^\\/channels\\/[^\\/]+\\/publish$/.test(window.location.pathname)', 600))->toBeTrue();

    $channel = SocialAccount::query()->where('platform_user_id', $did)->sole();

    expect($page->script('window.location.pathname'))->toBe(parse_url(route('app.channels.publish', $channel), PHP_URL_PATH));

    return [$page, $channel];
}

test('a newly created channel opens the goal flow and saves the chosen goal', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
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

    [$page, $channel] = connectPostingGoalChannel();
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

    [$page, $channel] = connectPostingGoalChannel();
    waitForPostingGoalTestId($page, 'goal-next');
    $page->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-customize');
    $page->click('@goal-customize');
    waitForPostingGoalTestId($page, 'channel-settings-page');

    $page->assertVisible('@channel-settings-page')->assertNoJavaScriptErrors();
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

    [$page, $channel] = connectPostingGoalChannel();
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

test('on a phone the goal step shows a full-width Next above the help', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
    $page->resize(390, 844);
    waitForPostingGoalTestId($page, 'goal-next');

    expect(postingGoalPollFor($page, 'window.innerWidth === 390 && document.querySelector(\'[data-testid="connect-channel-dialog"]\').getAnimations().length === 0'))->toBeTrue();

    $layout = $page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const footer = rect('goal-footer');
            const help = rect('goal-help');
            const next = rect('goal-next');
            const perWeekLines = [...document.querySelectorAll('[data-testid="goal-option-label"] > span:nth-child(2)')]
                .map((el) => Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight)));

            return {
                nextAlignedWithOptions: (() => { const option = rect('goal-option-custom'); return Math.abs(next.left - option.left) <= 1 && Math.abs(next.right - option.right) <= 1 && Math.round(next.top - option.bottom) === 24; })(),
                nextAboveHelp: next.bottom <= help.top,
                helpInside: help.left >= footer.left && help.right <= footer.right,
                footerBorder: getComputedStyle(document.querySelector('[data-testid="goal-footer"]')).borderTopWidth,
                perWeekOnOneLine: perWeekLines.every((lines) => lines === 1),
            };
        })();
    JS);

    expect($layout)->toBe(['nextAlignedWithOptions' => true, 'nextAboveHelp' => true, 'helpInside' => true, 'footerBorder' => '0px', 'perWeekOnOneLine' => true]);

    $page->assertNoJavaScriptErrors();
});

test('on a phone the recommended step stacks Done, Customize and Change goal in full width', function () {
    [$user, $channel] = postingGoalSetup();
    $user->update(['locale' => Locale::PortugueseBrazil]);
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
    $page->resize(390, 844);
    waitForPostingGoalTestId($page, 'goal-next');
    $page->click('@goal-next');
    waitForPostingGoalTestId($page, 'goal-recommended');

    $layout = $page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const row = rect('goal-recommended-row');
            const done = rect('goal-done');
            const customize = rect('goal-customize');
            const change = rect('goal-change');
            const sameWidth = (box) => Math.abs(box.left - row.left) <= 1 && Math.abs(box.right - row.right) <= 1;

            return {
                doneFullWidth: sameWidth(done),
                customizeFullWidth: sameWidth(customize),
                order: done.bottom <= customize.top && customize.bottom <= change.top,
                changeOnOneLine: Math.round(change.height) <= 40,
            };
        })();
    JS);

    expect($layout)->toBe(['doneFullWidth' => true, 'customizeFullWidth' => true, 'order' => true, 'changeOnOneLine' => true]);
    $page->assertNoJavaScriptErrors();
});

test('the recommended-time help opens above its trigger and links to the docs', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
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

    [$page, $channel] = connectPostingGoalChannel();
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

    [$page, $channel] = connectPostingGoalChannel();

    expect(postingGoalPollFor($page, 'document.querySelector(\'[data-testid="confetti-canvas"]\')'))->toBeTrue()
        ->and(postingGoalPollFor($page, '!document.querySelector(\'[data-testid="confetti-canvas"]\')'))->toBeTrue();

    $page->assertVisible('@goal-flow')->assertNoJavaScriptErrors();
});

test('confetti stays off when the user prefers reduced motion', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel(prepare: fn (mixed $page) => $page->script(<<<'JS'
        (() => {
            const original = window.matchMedia.bind(window);
            window.matchMedia = (query) => query.includes('prefers-reduced-motion')
                ? { matches: true, media: query, onchange: null, addListener() {}, removeListener() {}, addEventListener() {}, removeEventListener() {}, dispatchEvent: () => false }
                : original(query);
        })();
    JS));
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
