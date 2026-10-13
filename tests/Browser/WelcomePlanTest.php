<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Models\User;
use App\Models\Workspace;

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

test('the plan step shows the offer, what is included and the G2 rating', function () {
    config(['trypost.self_hosted' => false, 'cashier.first_month_coupon_ids.socials' => 'coupon_socials']);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));

    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'welcome-plan-reviews');
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->assertRoute('app.welcome.plan')
        ->assertSee('Activate your account')
        ->assertSee('Connect your channels and schedule your first post today.')
        ->assertMissing('@plan-card-workspaces')
        ->assertSeeIn('@plan-price-socials', '$1')
        ->assertSeeIn('@plan-price-suffix-socials', 'for your first month')
        ->assertVisible('@plan-networks-info-socials')
        ->assertSeeIn('@welcome-plan-continue', 'Activate for $1')
        ->assertSeeIn('@welcome-plan-terms', 'Then $19/month. Cancel anytime.')
        ->assertVisible('@welcome-step-plan')
        ->assertNoJavaScriptErrors();

    $reviews = $page->script(<<<'JS'
        document.querySelectorAll('[data-testid^="welcome-plan-reviewer-"]').length
    JS);

    expect($reviews)->toBe(5)
        ->and($page->script('document.querySelector("[data-testid=plan-feature-label-socials-analytics]").closest("li").querySelector("svg").classList.contains("tabler-icon-trending-up")'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-testid=plan-feature-label-socials-mcp]").closest("li").querySelector("svg").classList.contains("tabler-icon-settings-ai")'))->toBeTrue()
        ->and($page->script('[...document.querySelectorAll("[data-testid=plan-feature-list-socials] ul > li > svg")].map((icon) => Math.round(icon.getBoundingClientRect().width))'))->each->toBe(20)
        ->and($page->script('[...document.querySelectorAll("[data-testid^=plan-feature-label-socials-]")].map((label) => label.dataset.testid.replace("plan-feature-label-socials-", ""))'))->toBe(['networks_all', 'accounts_unlimited', 'ideas', 'ai', 'calendar', 'analytics', 'mcp', 'repurpose', 'team']);

    $page->assertSeeIn('@plan-feature-label-socials-team', 'Unlimited team members')
        ->assertSeeIn('@plan-feature-label-socials-networks_all', 'All social networks included')
        ->assertSeeIn('@plan-feature-label-socials-analytics', 'Advanced analytics')
        ->assertSeeIn('@plan-feature-label-socials-ideas', 'Content planning')
        ->assertVisible('@plan-feature-info-socials-ideas');
});

test('the plan step shows the full price when checkout will not apply the first-month coupon', function () {
    config(['trypost.self_hosted' => false, 'cashier.first_month_coupon_ids.socials' => '']);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->assertSeeIn('@plan-price-socials', '$19')
        ->assertSeeIn('@plan-price-suffix-socials', 'per month')
        ->assertSeeIn('@welcome-plan-continue', 'Activate for $19')
        ->assertSeeIn('@welcome-plan-terms', 'Cancel anytime.')
        ->assertDontSee('first month')
        ->assertNoJavaScriptErrors();

    expect($page->script('document.querySelector("[data-testid=plan-price-socials]").textContent.trim()'))->toBe('$19');
});

test('the plan step does not list the workspace limit, so team members close the list', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'plan-feature-list-socials');

    $last = $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="plan-feature-list-socials"] ul > li')].at(-1)?.querySelector('[data-testid^="plan-feature-label-"]')?.dataset.testid
    JS);

    expect($last)->toBe('plan-feature-label-socials-team');

    $page->assertMissing('@plan-highlight-socials')
        ->assertMissing('@plan-workspaces-info-socials')
        ->assertNoJavaScriptErrors();
});

test('the review quotes rotate on their own', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-review-paulo_dantas');

    $visibleReview = <<<'JS'
        document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid
    JS;

    expect($page->script($visibleReview))->toBe('welcome-plan-review-paulo_dantas');

    $next = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                const visible = document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid;
                if (visible !== 'welcome-plan-review-paulo_dantas') return visible;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })()
    JS);

    expect($next)->toBe('welcome-plan-review-diego');
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

    $stillPedro = $page->script(<<<'JS'
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 7000));
            return document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.dataset.testid;
        })()
    JS);

    expect($stillPedro)->toBe('welcome-plan-review-pedro');

    $opacities = $page->script(<<<'JS'
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

    expect($opacities)->toBe(['active' => [1], 'inactive' => [0.5]]);
});

test('the plan step fits a phone without scrolling and hides the quotes there', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'))->resize(390, 844);
    waitForWelcomePlanTestId($page, 'plan-card-socials');

    $layout = $page->script(<<<'JS'
        ({
            scrolls: document.documentElement.scrollHeight > window.innerHeight,
            quoteVisible: document.querySelector('[data-testid^="welcome-plan-review-"]:not([aria-hidden="true"])')?.getClientRects().length > 0,
        })
    JS);

    expect($layout)->toBe(['scrolls' => false, 'quoteVisible' => false]);

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

    $opened = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                const content = document.querySelector('[data-slot="popover-content"]');
                if (content) return content.textContent.trim();
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);

    expect($opened)->toStartWith('Invite as many people as you want');

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

    $page->script('document.querySelector("[data-testid=plan-feature-info-socials-team]").focus()');
    $page->keys('@plan-feature-info-socials-team', 'Enter');

    $opened = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (document.querySelector('[data-slot="popover-content"]')) return true;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
    JS);

    expect($opened)->toBeTrue();

    $page->keys('@plan-feature-info-socials-team', 'Escape');

    $closed = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-slot="popover-content"]')) return true;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
    JS);

    expect($closed)->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('the activate button shows a spinner while checkout opens', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->script(<<<'JS'
        XMLHttpRequest.prototype.send = function () {};
    JS);
    $page->click('@welcome-plan-continue');

    $state = $page->script(<<<'JS'
        (async () => {
            const button = document.querySelector('[data-testid="welcome-plan-continue"]');
            for (let i = 0; i < 50; i++) {
                if (button.querySelector('[role="status"]')) return { spinner: true, disabled: button.disabled };
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return { spinner: false, disabled: button.disabled };
        })()
    JS);

    expect($state)->toBe(['spinner' => true, 'disabled' => true]);
});
