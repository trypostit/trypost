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
function waitForWelcomeTestId(mixed $page, string $testId): void
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

function welcomeOwnerOnConnectStep(): User
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

    return $user->fresh();
}

test('connect step shows the grid and keeps continue disabled without a social account', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-start-checkout');

    $page->assertRoute('app.welcome.connect')
        ->assertVisible('@welcome-connect-grid')
        ->assertVisible('@welcome-start-checkout')
        ->assertDisabled('@welcome-start-checkout')
        ->assertVisible('@welcome-step-4')
        ->assertNoJavaScriptErrors();
});

test('connect step enables continue when a social account is connected', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-start-checkout');

    $page->assertRoute('app.welcome.connect')
        ->assertVisible('@welcome-connect-grid')
        ->assertEnabled('@welcome-start-checkout')
        ->assertNoJavaScriptErrors();
});

test('connect step can go back to referral', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-step-3');

    $page->click('@welcome-step-3');

    waitForWelcomeTestId($page, 'welcome-referral-continue');

    $page->assertRoute('app.welcome.referral-source')
        ->assertVisible('@welcome-referral-continue')
        ->assertNoJavaScriptErrors();
});

test('connect step redirects to persona when prior steps are missing', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    $page->assertRoute('app.welcome.persona')
        ->assertNoJavaScriptErrors();
});
