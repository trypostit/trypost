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

function postingGoalTestIdSelector(string $testId, ?string $within = null): string
{
    $selector = "[data-testid=\"{$testId}\"]";

    return $within === null ? $selector : "[data-testid=\"{$within}\"] {$selector}";
}

function postingGoalTestIdAppearsWithin(mixed $page, string $testId, int $attempts = 120): bool
{
    $selector = json_encode(postingGoalTestIdSelector($testId));

    return postingGoalPollFor($page, "document.querySelector({$selector}) !== null", $attempts);
}

function waitForPostingGoalTestIdGone(mixed $page, string $testId, int $attempts = 120): void
{
    $selector = json_encode(postingGoalTestIdSelector($testId));

    postingGoalPollFor($page, "document.querySelector({$selector}) === null", $attempts);
}

function waitForPostingGoalViewportSettled(mixed $page, int $width, string $testId): bool
{
    $selector = json_encode(postingGoalTestIdSelector($testId));

    return postingGoalPollFor($page, "window.innerWidth === {$width} && document.querySelector({$selector}).getAnimations().length === 0");
}

/**
 * @return array{left: float, right: float, top: float, bottom: float, width: float, height: float}
 */
function postingGoalRect(mixed $page, string $testId, ?string $within = null): array
{
    $selector = json_encode(postingGoalTestIdSelector($testId, $within));

    return $page->script(<<<JS
        (() => {
            const { left, right, top, bottom, width, height } = document.querySelector({$selector}).getBoundingClientRect();

            return { left, right, top, bottom, width, height };
        })();
    JS);
}

function postingGoalStyle(mixed $page, string $testId, string $property): string
{
    $selector = json_encode(postingGoalTestIdSelector($testId));
    $property = json_encode($property);

    return $page->script("getComputedStyle(document.querySelector({$selector}))[{$property}]");
}

function postingGoalOffsetWidth(mixed $page, string $testId): int
{
    $selector = json_encode(postingGoalTestIdSelector($testId));

    return $page->script("document.querySelector({$selector}).offsetWidth");
}

function postingGoalInnerText(mixed $page, string $testId): string
{
    $selector = json_encode(postingGoalTestIdSelector($testId));

    return $page->script("document.querySelector({$selector}).innerText.replace(/\\s+/g, ' ').trim()");
}

/**
 * @return list<int>
 */
function postingGoalPerWeekLineCounts(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="goal-option-label"] > span:nth-child(2)')]
            .map((el) => Math.round(el.getBoundingClientRect().height / parseFloat(getComputedStyle(el).lineHeight)));
    JS);
}

/**
 * @return list<list<string>>
 */
function postingGoalRecommendedTimesPerRow(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="goal-recommended-row"]')]
            .map((row) => [...row.querySelectorAll('strong')].map((el) => el.textContent.trim()));
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

    $page->assertCount('@goal-recommended-row', 5);
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

test('the goal step matches the wide card layout with right-side radios', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
    waitForPostingGoalTestId($page, 'goal-option-3');

    $row = postingGoalRect($page, 'goal-option-3');
    $badge = postingGoalRect($page, 'goal-option-badge', within: 'goal-option-3');
    $radio = postingGoalRect($page, 'goal-option-radio', within: 'goal-option-3');
    $help = postingGoalRect($page, 'goal-help');
    $next = postingGoalRect($page, 'goal-next');

    expect(postingGoalOffsetWidth($page, 'connect-channel-dialog'))->toBe(800)
        ->and((int) round($row['height']))->toBeGreaterThanOrEqual(62)
        ->and($radio['left'])->toBeGreaterThan($badge['right'])
        ->and($row['right'] - $radio['right'])->toBeLessThan(40)
        ->and(postingGoalStyle($page, 'goal-option-3', 'backgroundColor'))->toBe('rgba(0, 0, 0, 0)')
        ->and(postingGoalStyle($page, 'goal-option-3', 'borderColor'))->not->toBe(postingGoalStyle($page, 'goal-option-1', 'borderColor'))
        ->and(postingGoalStyle($page, 'goal-footer', 'borderTopWidth'))->toBe('1px')
        ->and($help['right'])->toBeLessThan($next['left'])
        ->and(postingGoalInnerText($page, 'goal-option-3'))->toBe('3x Build a presence · 3 times/week');

    $page->assertNoJavaScriptErrors();
});

test('on a phone the goal step shows a full-width Next above the help', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
    $page->resize(390, 844);
    waitForPostingGoalTestId($page, 'goal-next');

    expect(waitForPostingGoalViewportSettled($page, 390, 'connect-channel-dialog'))->toBeTrue();

    $footer = postingGoalRect($page, 'goal-footer');
    $help = postingGoalRect($page, 'goal-help');
    $next = postingGoalRect($page, 'goal-next');
    $option = postingGoalRect($page, 'goal-option-custom');

    expect(abs($next['left'] - $option['left']))->toBeLessThanOrEqual(1)
        ->and(abs($next['right'] - $option['right']))->toBeLessThanOrEqual(1)
        ->and((int) round($next['top'] - $option['bottom']))->toBe(24)
        ->and($next['bottom'])->toBeLessThanOrEqual($help['top'])
        ->and($help['left'])->toBeGreaterThanOrEqual($footer['left'])
        ->and($help['right'])->toBeLessThanOrEqual($footer['right'])
        ->and(postingGoalStyle($page, 'goal-footer', 'borderTopWidth'))->toBe('0px')
        ->and(postingGoalPerWeekLineCounts($page))->each->toBe(1);

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

    $row = postingGoalRect($page, 'goal-recommended-row');
    $done = postingGoalRect($page, 'goal-done');
    $customize = postingGoalRect($page, 'goal-customize');
    $change = postingGoalRect($page, 'goal-change');

    expect(abs($done['left'] - $row['left']))->toBeLessThanOrEqual(1)
        ->and(abs($done['right'] - $row['right']))->toBeLessThanOrEqual(1)
        ->and(abs($customize['left'] - $row['left']))->toBeLessThanOrEqual(1)
        ->and(abs($customize['right'] - $row['right']))->toBeLessThanOrEqual(1)
        ->and($done['bottom'])->toBeLessThanOrEqual($customize['top'])
        ->and($customize['bottom'])->toBeLessThanOrEqual($change['top'])
        ->and((int) round($change['height']))->toBeLessThanOrEqual(40);
    $page->assertNoJavaScriptErrors();
});

test('the recommended-time help opens above its trigger and links to the docs', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();
    waitForPostingGoalTestId($page, 'goal-help');
    $page->click('@goal-help');
    waitForPostingGoalTestId($page, 'goal-help-popover');

    $trigger = postingGoalRect($page, 'goal-help');
    $card = postingGoalRect($page, 'goal-help-popover');

    expect($card['bottom'])->toBeLessThanOrEqual($trigger['top'])
        ->and(postingGoalRect($page, 'goal-help-learn-more')['right'])->toBeLessThan(postingGoalRect($page, 'goal-help-close')['left']);

    $page->assertAttribute('@goal-help-learn-more', 'href', 'https://docs.trypost.it')
        ->assertAttribute('@goal-help-learn-more', 'target', '_blank')
        ->assertAttributeContains('@goal-help-learn-more', 'rel', 'noopener');

    $page->click('@goal-help-close');
    waitForPostingGoalTestIdGone($page, 'goal-help-popover');

    $page->assertNotPresent('@goal-help-popover');
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

    $timesPerRow = postingGoalRecommendedTimesPerRow($page);

    expect(array_map('count', $timesPerRow))->toBe([2, 2, 2])
        ->and(array_merge(...$timesPerRow))->each->toMatch('/^\d{2}:00$/')
        ->and(postingGoalRect($page, 'goal-change')['left'])->toBeLessThan(postingGoalRect($page, 'goal-customize')['left'])
        ->and(postingGoalRect($page, 'goal-customize')['left'])->toBeLessThan(postingGoalRect($page, 'goal-done')['left']);

    $page->assertAttribute('@goal-learn-more', 'href', 'https://docs.trypost.it')
        ->assertNotPresent('[data-sonner-toast]');

    $page->click('@goal-change');
    waitForPostingGoalTestId($page, 'goal-flow');
    $page->assertVisible('@goal-option-3')->assertNoJavaScriptErrors();
});

test('connecting a channel fires a confetti burst that cleans up after itself', function () {
    [$user, $channel] = postingGoalSetup();
    $this->actingAs($user);

    [$page, $channel] = connectPostingGoalChannel();

    waitForPostingGoalTestId($page, 'confetti-canvas');
    $page->assertPresent('@confetti-canvas');

    waitForPostingGoalTestIdGone($page, 'confetti-canvas', 200);
    $page->assertNotPresent('@confetti-canvas');

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

    expect(postingGoalTestIdAppearsWithin($page, 'confetti-canvas', 20))->toBeFalse();
    $page->assertNotPresent('@confetti-canvas');
    $page->assertNoJavaScriptErrors();
});

function fakeBlueskyIdentity(string $did): void
{
    $service = config('trypost.platforms.bluesky.default_service');

    Http::fake([
        "{$service}/xrpc/com.atproto.server.createSession" => Http::response([
            'emailConfirmed' => true,
            'did' => $did,
            'handle' => 'goal.bsky.social',
            'accessJwt' => 'access-token',
            'refreshJwt' => 'refresh-token',
        ]),
        "{$service}/xrpc/com.atproto.server.getSession" => Http::response([
            'did' => $did,
            'handle' => 'goal.bsky.social',
            'emailConfirmed' => true,
        ]),
        "{$service}/xrpc/app.bsky.actor.getProfile*" => Http::response([
            'did' => $did,
            'handle' => 'goal.bsky.social',
            'displayName' => 'Goal Bluesky',
        ]),
    ]);
}
