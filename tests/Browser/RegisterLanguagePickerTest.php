<?php

declare(strict_types=1);

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForRegisterLanguageTestId(mixed $page, string $testId): void
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

beforeEach(fn () => config(['trypost.self_hosted' => false]));

test('the register screen offers a language picker starting on the negotiated locale', function () {
    $page = visit(route('register'));

    waitForRegisterLanguageTestId($page, 'language-picker');

    $page->assertVisible('@language-picker')
        ->assertSee('English')
        ->assertNoJavaScriptErrors();
});

test('picking a language translates the register screen without a page load', function () {
    $page = visit(route('register'));

    waitForRegisterLanguageTestId($page, 'language-picker');

    $page->click('@language-picker');
    waitForRegisterLanguageTestId($page, 'language-option-pt-BR');

    $page->click('@language-option-pt-BR');
    waitForRegisterLanguageTestId($page, 'language-picker');

    $page->assertSee('Criar conta')
        ->assertSee('Português')
        ->assertNoJavaScriptErrors();
});
