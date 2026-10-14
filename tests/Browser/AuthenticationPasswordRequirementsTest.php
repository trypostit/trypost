<?php

declare(strict_types=1);

use App\Models\User;

function waitForAuthenticationPasswordRequirement(mixed $page, string $key, string $met): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="password-requirement-{$key}"][data-met="{$met}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('setting or changing a password shows the same requirements as sign up', function (bool $hasPassword) {
    $user = User::factory()->create($hasPassword ? [] : ['password' => null]);
    $this->actingAs($user);

    $page = visit(route('app.authentication.edit'));
    waitForAuthenticationPasswordRequirement($page, 'min', 'false');

    $page->assertSeeIn('@password-requirement-min', 'At least 12 characters')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@authentication-new-password', 'abcD1!abcdef');
    waitForAuthenticationPasswordRequirement($page, 'min', 'true');

    $page->assertAttribute('@password-requirement-min', 'data-met', 'true')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'true')
        ->assertAttribute('@password-requirement-number', 'data-met', 'true')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'true')
        ->assertNoJavaScriptErrors();
})->with(['with a password' => true, 'signed up with Google or GitHub' => false]);
