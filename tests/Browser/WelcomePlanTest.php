<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
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
}

function welcomeOwnerOnPlanStep(): User
{
    $user = User::factory()->create();
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
    ]);

    return $user->fresh();
}

test('the plan step shows both plans with networks and no yearly toggle', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeOwnerOnPlanStep());

    $page = visit(route('app.welcome.plan'));

    waitForWelcomePlanTestId($page, 'plan-networks-info-socials');
    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'plan-card-workspaces');
    waitForWelcomePlanTestId($page, 'plan-price-first-month-socials');
    waitForWelcomePlanTestId($page, 'language-picker');

    $page->assertRoute('app.welcome.plan')
        ->assertVisible('@plan-card-socials')
        ->assertVisible('@plan-card-workspaces')
        ->assertMissing('@plan-price-regular-socials')
        ->assertMissing('@plan-price-regular-workspaces')
        ->assertVisible('@plan-price-first-month-socials')
        ->assertVisible('@plan-price-first-month-workspaces')
        ->assertSeeIn('@plan-price-first-month-socials', '$1')
        ->assertSeeIn('@plan-price-first-month-workspaces', '$1')
        ->assertSeeIn('@plan-price-suffix-socials', '/first month')
        ->assertSeeIn('@plan-price-suffix-workspaces', '/first month')
        ->assertSeeIn('@plan-price-note-socials', 'Then $19/month')
        ->assertSeeIn('@plan-price-note-workspaces', 'Then $99/month')
        ->assertSeeIn('@plan-highlight-socials', 'One workspace')
        ->assertSeeIn('@plan-highlight-workspaces', 'Unlimited workspaces')
        ->assertVisible('@plan-workspaces-info-socials')
        ->assertVisible('@plan-workspaces-info-workspaces')
        ->assertVisible('@plan-networks-info-socials')
        ->assertVisible('@plan-networks-info-workspaces')
        ->assertVisible('@plan-select-socials')
        ->assertVisible('@plan-select-workspaces')
        ->assertVisible('@plan-highlight-socials')
        ->assertVisible('@plan-highlight-workspaces')
        ->assertVisible('@language-picker')
        ->assertMissing('@plan-interval-yearly')
        ->assertMissing('@plan-interval-monthly')
        ->assertNoJavaScriptErrors();
});
