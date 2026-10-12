<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Enums\User\Theme;
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

function welcomeHeaderOwner(): User
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

test('the software step goes back to goals, the step right before it', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeHeaderOwner());

    $page = visit(route('app.welcome.software'));
    waitForWelcomeTestId($page, 'welcome-back');

    $page->assertVisible('@welcome-step-plan')
        ->click('@welcome-back');
    waitForWelcomeTestId($page, 'welcome-goals-continue');

    $page->assertRoute('app.welcome.goals')
        ->assertNoJavaScriptErrors();
});

test('the plan step goes back to software, the step right before it', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeHeaderOwner());

    $page = visit(route('app.welcome.plan'));
    waitForWelcomeTestId($page, 'welcome-back');

    $page->click('@welcome-back');
    waitForWelcomeTestId($page, 'welcome-software-continue');

    $page->assertRoute('app.welcome.software')
        ->assertNoJavaScriptErrors();
});

test('the welcome header switches language through the shared language select and theme through the toggle', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeHeaderOwner();
    $user->update(['locale' => Locale::English, 'theme' => Theme::Light]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.software'));
    waitForWelcomeTestId($page, 'welcome-language-trigger');

    $page->assertVisible('@welcome-back')
        ->assertVisible('@welcome-step-software')
        ->click('@welcome-language-trigger');
    waitForWelcomeTestId($page, 'welcome-language-search');

    $page->fill('@welcome-language-search', 'Português')
        ->click('@welcome-language-option-pt-BR');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (document.querySelector('[data-testid="welcome-language-value"]')?.textContent.includes('Portugu')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    expect($user->fresh()->locale)->toBe(Locale::PortugueseBrazil);

    $page->click('@theme-toggle');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (document.documentElement.classList.contains('dark')) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
    $page->assertNoJavaScriptErrors();

    expect($user->fresh()->theme)->toBe(Theme::Dark);
});
