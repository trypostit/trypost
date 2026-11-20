<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Locale;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Enums\User\Theme;
use App\Models\User;
use App\Models\Workspace;

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
    waitFor($page, '@welcome-back');

    $page->assertVisible('@welcome-step-plan')
        ->click('@welcome-back');
    waitFor($page, '@welcome-goals-continue');

    $page->assertRoute('app.welcome.goals')
        ->assertNoJavaScriptErrors();
});

test('the software step shows each competitor by its logo and the other options by an icon', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeHeaderOwner());

    $page = visit(route('app.welcome.software'));

    foreach (PreviousSoftware::cases() as $software) {
        if (blank($software->brandName())) {
            continue;
        }

        waitForState($page, "@welcome-software-{$software->value}-logo", 'element.complete && element.naturalWidth > 0');
        $page->assertScript("document.querySelector('[data-testid=\"welcome-software-{$software->value}-logo\"]').naturalWidth > 0");
    }

    $page->assertMissing('@welcome-software-none-logo')
        ->assertMissing('@welcome-software-other-logo')
        ->assertVisible('@welcome-software-none-icon')
        ->assertVisible('@welcome-software-other-icon')
        ->assertNoJavaScriptErrors();
});

test('the plan step goes back to software, the step right before it', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeHeaderOwner());

    $page = visit(route('app.welcome.plan'));
    waitFor($page, '@welcome-back');

    $page->click('@welcome-back');
    waitFor($page, '@welcome-software-continue');

    $page->assertRoute('app.welcome.software')
        ->assertNoJavaScriptErrors();
});

test('the welcome header switches language through the shared language select and theme through the toggle', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeHeaderOwner();
    $user->update(['locale' => Locale::English, 'theme' => Theme::Light]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.software'));
    waitFor($page, '@welcome-language-trigger');

    $page->assertVisible('@welcome-back')
        ->assertVisible('@welcome-step-software')
        ->click('@welcome-language-trigger');
    waitFor($page, '@welcome-language-search');

    $page->fill('@welcome-language-search', 'Português')
        ->click('@welcome-language-option-pt-BR');
    waitForState($page, '@welcome-language-value', "element.textContent.includes('Portugu')");

    expect($user->fresh()->locale)->toBe(Locale::PortugueseBrazil);

    $page->click('@theme-toggle');
    waitForState($page, 'html', "element.classList.contains('dark')");
    $page->assertNoJavaScriptErrors();

    expect($user->fresh()->theme)->toBe(Theme::Dark);
});
