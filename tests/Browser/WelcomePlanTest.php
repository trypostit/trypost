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
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));

    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'welcome-plan-reviews');
    waitForWelcomePlanTestId($page, 'welcome-plan-continue');

    $page->assertRoute('app.welcome.plan')
        ->assertSee('Activate your account')
        ->assertSee('Connect your channels and schedule your first post today.')
        ->assertMissing('@plan-card-workspaces')
        ->assertSeeIn('@plan-price-first-month-socials', '$1')
        ->assertSeeIn('@plan-price-suffix-socials', 'for your first month')
        ->assertSeeIn('@plan-highlight-socials', 'One workspace')
        ->assertVisible('@plan-networks-info-socials')
        ->assertSeeIn('@welcome-plan-reviews', '5/5 on G2')
        ->assertSeeIn('@welcome-plan-continue', 'Activate for $1')
        ->assertSeeIn('@welcome-plan-terms', 'Then $19/month. Cancel anytime.')
        ->assertSeeIn('@welcome-plan-secure-checkout', 'Secure checkout with Stripe')
        ->assertVisible('@welcome-step-plan')
        ->assertNoJavaScriptErrors();

    $reviews = $page->script(<<<'JS'
        document.querySelectorAll('[data-testid="welcome-plan-reviews"] img').length
    JS);

    expect($reviews)->toBe(5);
});

test('One workspace is the last item of what is included', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomePlanTestId($page, 'plan-highlight-socials');

    $last = $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="plan-feature-list-socials"] li')].at(-1)?.dataset.testid
    JS);

    expect($last)->toBe('plan-highlight-socials');
});
