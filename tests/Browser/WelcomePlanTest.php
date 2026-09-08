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

    waitForWelcomePlanTestId($page, 'plan-networks-socials');
    waitForWelcomePlanTestId($page, 'plan-card-socials');
    waitForWelcomePlanTestId($page, 'plan-card-workspaces');
    waitForWelcomePlanTestId($page, 'language-picker');

    $page->assertRoute('app.welcome.plan')
        ->assertVisible('@plan-card-socials')
        ->assertVisible('@plan-card-workspaces')
        ->assertVisible('@plan-networks-socials')
        ->assertVisible('@plan-networks-workspaces')
        ->assertVisible('@plan-select-socials')
        ->assertVisible('@plan-select-workspaces')
        ->assertVisible('@plan-highlight-socials')
        ->assertVisible('@plan-highlight-workspaces')
        ->assertVisible('@language-picker')
        ->assertMissing('@plan-interval-yearly')
        ->assertMissing('@plan-interval-monthly')
        ->assertNoJavaScriptErrors();
});
