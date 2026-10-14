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
