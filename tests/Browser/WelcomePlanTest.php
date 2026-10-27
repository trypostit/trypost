<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Models\Account;
use App\Models\Offer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForWelcomePlanTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    waitForWebFonts($page);
}

function welcomePlanVisibleReview(mixed $page): ?string
{
    return $page->script(<<<'JS'
        document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid
    JS);
}

function waitForWelcomePlanReviewOtherThan(mixed $page, string $testId): ?string
{
    return $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const visible = document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid;
                if (visible !== '{$testId}') return visible;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })()
    JS);
}

function welcomePlanVisibleReviewAfter(mixed $page, int $milliseconds): ?string
{
    return $page->script(<<<JS
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, {$milliseconds}));
            return document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid;
        })()
    JS);
}

/**
 * @return array{active: list<float|int>, inactive: list<float|int>}|string
 */
function waitForWelcomePlanReviewerOpacities(mixed $page): array|string
{
    return $page->script(<<<'JS'
        (async () => {
            const opacityOf = (button) => Number(getComputedStyle(button).opacity);
            for (let i = 0; i < 40; i++) {
                const buttons = [...document.querySelectorAll('[data-testid^="welcome-plan-reviewer-"]')];
                const active = buttons.filter((button) => button.getAttribute('aria-pressed') === 'true').map(opacityOf);
                const inactive = buttons.filter((button) => button.getAttribute('aria-pressed') !== 'true').map(opacityOf);
                if (active[0] === 1 && inactive.every((value) => value === 0.5)) {
                    return { active, inactive: [...new Set(inactive)] };
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return 'not settled';
        })()
    JS);
}

/**
 * @return list<string>
 */
function welcomePlanTestIdsStartingWith(mixed $page, string $prefix): array
{
    return $page->script(<<<JS
        [...document.querySelectorAll('[data-testid^="{$prefix}"]')].map((element) => element.dataset.testid)
    JS);
}

/**
 * @return list<string>
 */
function welcomePlanFeatureIconClasses(mixed $page, string $labelTestId): array
{
    return $page->script(<<<JS
        [...(document.querySelector('[data-testid="{$labelTestId}"]')?.closest('li')?.querySelector('svg')?.classList ?? [])]
    JS);
}

/**
 * @return list<int>
 */
function welcomePlanFeatureIconWidths(mixed $page, string $listTestId): array
{
    return $page->script(<<<JS
        [...document.querySelectorAll('[data-testid="{$listTestId}"] ul > li > svg')].map((icon) => Math.round(icon.getBoundingClientRect().width))
    JS);
}

function welcomePlanLastFeatureLabelTestId(mixed $page, string $listTestId): ?string
{
    return $page->script(<<<JS
        [...document.querySelectorAll('[data-testid="{$listTestId}"] ul > li')].at(-1)?.querySelector('[data-testid^="plan-feature-label-"]')?.dataset.testid
    JS);
}

/**
 * @return array{scrolls: bool, quoteVisible: bool}
 */
function welcomePlanPhoneLayout(mixed $page): array
{
    return $page->script(<<<'JS'
        ({
            scrolls: document.documentElement.scrollHeight > window.innerHeight,
            quoteVisible: document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.getClientRects().length > 0,
        })
    JS);
}

function waitForWelcomePlanPopoverOpen(mixed $page): bool
{
    return $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (document.querySelector('[data-slot="popover-content"]')) return true;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
    JS);
}

function waitForWelcomePlanPopoverClosed(mixed $page): bool
{
    return $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-slot="popover-content"]')) return true;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
    JS);
}

function welcomePlanFocus(mixed $page, string $testId): void
{
    $page->script(<<<JS
        document.querySelector('[data-testid="{$testId}"]').focus()
    JS);
}

function welcomePlanStallRequests(mixed $page): void
{
    $page->script(<<<'JS'
        XMLHttpRequest.prototype.send = function () {};
    JS);
}

/**
 * @return array{spinner: bool, disabled: bool}
 */
function waitForWelcomePlanButtonSpinner(mixed $page, string $testId): array
{
    return $page->script(<<<JS
        (async () => {
            const button = document.querySelector('[data-testid="{$testId}"]');
            for (let i = 0; i < 50; i++) {
                if (button.querySelector('[role="status"]')) return { spinner: true, disabled: button.disabled };
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return { spinner: false, disabled: button.disabled };
        })()
    JS);
}

function welcomeOwnerOnPlanStep(): User
{
    $user = User::factory()->create();
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'previous_software' => PreviousSoftware::Buffer->value,
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('the plan step shows the free trial, what is included and the G2 rating', function () {
    config(['trypost.self_hosted' => false, 'cashier.trial_days' => 8]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));

    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'welcome-plan-reviews');
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->assertRoute('app.welcome.plan')
        ->assertSee('Activate your account')
        ->assertSee('Connect your channels and schedule your first post today.')
        ->assertMissing('@plan-card-workspaces')
        ->assertSeeIn('@plan-price-socials', '$0')
        ->assertSeeIn('@plan-price-suffix-socials', 'for 8 days')
        ->assertVisible('@plan-networks-info-socials')
        ->assertSeeIn('@welcome-plan-continue', 'Start free trial')
        ->assertSeeIn('@welcome-plan-terms', 'Then $19/month. Cancel anytime.')
        ->assertMissing('@welcome-plan-offer')
        ->assertVisible('@welcome-step-plan')
        ->assertNoJavaScriptErrors();

    $page->assertCount('[data-testid^="welcome-plan-reviewer-"]', 5);

    $features = array_map(
        fn (string $testId): string => Str::after($testId, 'plan-feature-label-socials-'),
        welcomePlanTestIdsStartingWith($page, 'plan-feature-label-socials-'),
    );

    expect(welcomePlanFeatureIconClasses($page, 'plan-feature-label-socials-analytics'))->toContain('tabler-icon-trending-up')
        ->and(welcomePlanFeatureIconClasses($page, 'plan-feature-label-socials-mcp'))->toContain('tabler-icon-settings-ai')
        ->and(welcomePlanFeatureIconWidths($page, 'plan-feature-list-socials'))->each->toBe(20)
        ->and($features)->toBe(['networks_all', 'accounts_unlimited', 'ideas', 'ai', 'calendar', 'analytics', 'mcp', 'repurpose', 'team']);

    $page->assertSeeIn('@plan-feature-label-socials-team', 'Unlimited team members')
        ->assertSeeIn('@plan-feature-label-socials-networks_all', 'All social networks included')
        ->assertSeeIn('@plan-feature-label-socials-analytics', 'Advanced analytics')
        ->assertSeeIn('@plan-feature-label-socials-ideas', 'Content planning')
        ->assertVisible('@plan-feature-info-socials-ideas');
});

test('the plan step shows the full price to a former customer, who gets no trial', function () {
    config(['trypost.self_hosted' => false, 'cashier.trial_days' => 8]);

    $user = welcomeOwnerOnPlanStep();
    $user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_former',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_monthly_test',
        'ends_at' => now()->subDay(),
    ]);
    $this->actingAs($user);

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->assertSeeIn('@plan-price-socials', '$19')
        ->assertSeeIn('@plan-price-suffix-socials', 'per month')
        ->assertSeeIn('@welcome-plan-continue', 'Activate for $19')
        ->assertSeeIn('@welcome-plan-terms', 'Cancel anytime.')
        ->assertDontSee('8 days')
        ->assertNoJavaScriptErrors();

    expect(trim((string) $page->text('@plan-price-socials')))->toBe('$19');
});

test('the plan step does not list the workspace limit, so team members close the list', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'plan-feature-list-socials');

    expect(welcomePlanLastFeatureLabelTestId($page, 'plan-feature-list-socials'))->toBe('plan-feature-label-socials-team');

    $page->assertMissing('@plan-highlight-socials')
        ->assertMissing('@plan-workspaces-info-socials')
        ->assertNoJavaScriptErrors();
});

test('the review quotes rotate on their own', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-review-paulo_dantas');

    expect(welcomePlanVisibleReview($page))->toBe('welcome-plan-review-paulo_dantas')
        ->and(waitForWelcomePlanReviewOtherThan($page, 'welcome-plan-review-paulo_dantas'))->toBe('welcome-plan-review-diego');
});

test('clicking a reviewer photo shows their quote', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-reviewer-pedro');

    $page->click('@welcome-plan-reviewer-pedro');
    waitForWelcomePlanTestId($page, 'welcome-plan-review-pedro');

    $page->assertVisible('@welcome-plan-review-pedro')
        ->assertAttribute('@welcome-plan-reviewer-pedro', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();

    expect(welcomePlanVisibleReviewAfter($page, 7000))->toBe('welcome-plan-review-pedro')
        ->and(waitForWelcomePlanReviewerOpacities($page))->toBe(['active' => [1], 'inactive' => [0.5]]);
});

test('the plan step fits a phone without scrolling and hides the quotes there', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'))->resize(390, 844);
    waitForWelcomePlanTestId($page, 'plan-card-socials');

    expect(welcomePlanPhoneLayout($page))->toBe(['scrolls' => false, 'quoteVisible' => false]);

    $page->assertVisible('@welcome-plan-continue')
        ->assertNoJavaScriptErrors();
});

test('the feature info opens on a tap or click', function (int $width, int $height) {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'))->resize($width, $height);
    waitForWelcomePlanTestId($page, 'plan-feature-info-socials-team');

    $page->click('@plan-feature-info-socials-team');
    waitForWelcomePlanTestId($page, 'plan-feature-info-socials-team');

    expect(waitForWelcomePlanPopoverOpen($page))->toBeTrue()
        ->and(trim((string) $page->text('[data-slot="popover-content"]')))->toStartWith('Invite as many people as you want');

    $page->click('@plan-networks-info-socials');
    waitForWelcomePlanTestId($page, 'plan-networks-socials');

    $page->assertVisible('@plan-networks-socials')
        ->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1440, 900],
    'phone' => [390, 844],
]);

test('the feature info opens from the keyboard and closes with Escape', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'))->resize(1440, 900);
    waitForWelcomePlanTestId($page, 'plan-feature-info-socials-team');

    welcomePlanFocus($page, 'plan-feature-info-socials-team');
    $page->keys('@plan-feature-info-socials-team', 'Enter');

    expect(waitForWelcomePlanPopoverOpen($page))->toBeTrue();

    $page->keys('@plan-feature-info-socials-team', 'Escape');

    expect(waitForWelcomePlanPopoverClosed($page))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('the activate button shows a spinner while checkout opens', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    welcomePlanStallRequests($page);
    $page->click('@welcome-plan-continue');

    expect(waitForWelcomePlanButtonSpinner($page, 'welcome-plan-continue'))->toBe(['spinner' => true, 'disabled' => true]);
});

test('the plan step shows an offer\'s banner and price', function (string $slug, string $name, string $headline, string $price, string $period) {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnPlanStep();
    $user->account->offers()->attach(Offer::factory()->create(['slug' => $slug]), ['claimed_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('app.welcome.plan'));
    waitFor($page, '@welcome-plan-offer');

    $page->assertSeeIn('@welcome-plan-offer-name', $name)
        ->assertSeeIn('@welcome-plan-offer-headline', $headline)
        ->assertSeeIn('@plan-price-socials', $price)
        ->assertSeeIn('@plan-price-suffix-socials', $period)
        ->assertSeeIn('@welcome-plan-continue', "Activate for {$price}")
        ->assertSeeIn('@welcome-plan-terms', 'Then $19/month. Cancel anytime.')
        ->assertNoJavaScriptErrors();
})->with([
    'black friday' => ['bf26', 'Black Friday', '50% off your first 3 months', '$9.50', 'for your first 3 months'],
    'first month' => ['first-month', 'First month', 'Your first month for $1', '$1', 'for your first month'],
]);

test('the plan step shows neither a banner nor an offer price for an offer without copy', function () {
    config(['trypost.self_hosted' => false, 'cashier.trial_days' => 8]);

    $user = welcomeOwnerOnPlanStep();
    $user->account->offers()->attach(Offer::factory()->create(), ['claimed_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('app.welcome.plan'));
    waitFor($page, '@plan-price-socials');

    $page->assertMissing('@welcome-plan-offer')
        ->assertSeeIn('@plan-price-suffix-socials', 'per month')
        ->assertDontSee('offers.')
        ->assertNoJavaScriptErrors();

    expect(trim((string) $page->text('@plan-price-socials')))->toBe('$19');
});
