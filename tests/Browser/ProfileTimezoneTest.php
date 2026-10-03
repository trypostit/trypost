<?php

declare(strict_types=1);

use App\Models\User;

function waitForProfileTimezoneTestId(mixed $page, string $testId): void
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

test('a user changes their time zone on the preferences page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $page = visit(route('app.settings.preferences'));
    waitForProfileTimezoneTestId($page, 'preferences-timezone-trigger');
    $page->click('@preferences-timezone-trigger');
    waitForProfileTimezoneTestId($page, 'preferences-timezone-search');
    $page->type('@preferences-timezone-search', 'Warsaw');
    waitForProfileTimezoneTestId($page, 'preferences-timezone-option-Europe-Warsaw');
    $page->click('@preferences-timezone-option-Europe-Warsaw');
    waitForProfileTimezoneTestId($page, 'preferences-saved-timezone');

    expect($user->fresh()->timezone)->toBe('Europe/Warsaw');
    $page->assertNoJavaScriptErrors();
});

test('the profile page no longer shows the time zone', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));
    waitForProfileTimezoneTestId($page, 'header-title');

    $page->assertMissing('@profile-timezone-trigger')
        ->assertMissing('@preferences-timezone-trigger')
        ->assertNoJavaScriptErrors();
});

test('the register form sends the browser time zone', function () {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'));
    waitForProfileTimezoneTestId($page, 'register-timezone');

    expect($page->script("document.querySelector('[data-testid=\"register-timezone\"]').value"))->not->toBe('');
    $page->assertNoJavaScriptErrors();
});
