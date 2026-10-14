<?php

declare(strict_types=1);

use App\Models\User;

function waitForProfileTabsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('the profile tabs keep password, login and sessions apart and remember the open tab in the url', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));
    waitForProfileTabsTestId($page, 'profile-tab-login');

    $page->assertVisible('[data-test="update-profile-button"]')
        ->assertMissing('@profile-new-password')
        ->click('@profile-tab-login');
    waitForProfileTabsTestId($page, 'profile-new-password');

    $page->assertVisible('[data-test="connected-account-google"]')
        ->assertQueryStringHas('tab', 'login')
        ->click('@profile-tab-sessions');
    waitForProfileTabsTestId($page, 'profile-sessions');

    $page->assertQueryStringHas('tab', 'sessions')
        ->assertMissing('@profile-new-password')
        ->assertNoJavaScriptErrors();
});

test('a link to the login tab opens it', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit', ['tab' => 'login']));
    waitForProfileTabsTestId($page, 'profile-new-password');

    $page->assertVisible('@profile-new-password')
        ->assertMissing('[data-test="update-profile-button"]')
        ->assertNoJavaScriptErrors();
});

test('the delete account button sits under a neutral warning', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));
    waitForProfileTabsTestId($page, 'delete-account-warning');

    expect($page->script(<<<'JS'
        (() => {
            const warning = document.querySelector('[data-testid="delete-account-warning"]').getBoundingClientRect();
            const button = document.querySelector('[data-test="delete-user-button"]').getBoundingClientRect();
            const warningColor = getComputedStyle(document.querySelector('[data-testid="delete-account-warning"]')).backgroundColor;
            const buttonColor = getComputedStyle(document.querySelector('[data-test="delete-user-button"]')).backgroundColor;
            return button.top >= warning.bottom && Math.round(button.left) === Math.round(warning.left) && warningColor !== buttonColor;
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
