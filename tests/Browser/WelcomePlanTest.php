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

test('the plan step shows only Socials with the first-month offer and no yearly toggle', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));

    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'plan-price-first-month-socials');
    waitForWelcomePlanTestId($page, 'welcome-language-trigger');

    $page->assertRoute('app.welcome.plan')
        ->assertVisible('@plan-card-socials')
        ->assertMissing('@plan-card-workspaces')
        ->assertMissing('@plan-price-socials')
        ->assertSeeIn('@plan-price-first-month-socials', '$1')
        ->assertSeeIn('@plan-price-suffix-socials', '/first month')
        ->assertSeeIn('@plan-price-note-socials', 'Then $19/month')
        ->assertSeeIn('@plan-select-socials', 'Start for $1')
        ->assertSeeIn('@plan-highlight-socials', 'One workspace')
        ->assertVisible('@welcome-step-plan')
        ->assertMissing('@plan-interval-yearly')
        ->assertMissing('@plan-interval-monthly')
        ->assertNoJavaScriptErrors();
});
