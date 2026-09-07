<?php

declare(strict_types=1);

use App\Models\User;

function waitForAuthLanguageTestId(mixed $page, string $testId): void
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

test('every logged-out auth screen offers the language switcher', function (string $route) {
    $page = visit(route($route, $route === 'password.reset' ? ['token' => 'preview-token'] : []));

    waitForAuthLanguageTestId($page, 'language-picker');

    $page->assertVisible('@language-picker')->assertNoJavaScriptErrors();
})->with(['login', 'register', 'password.request', 'password.reset']);

test('the switcher is hidden once the visitor is authenticated', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('verification.notice'));

    $page->assertMissing('@language-picker')->assertNoJavaScriptErrors();
});

test('the picked language survives navigating between auth screens', function () {
    $page = visit(route('login'));

    waitForAuthLanguageTestId($page, 'language-picker');
    $page->click('@language-picker');
    waitForAuthLanguageTestId($page, 'language-option-pt-BR');
    $page->click('@language-option-pt-BR');

    waitForAuthLanguageTestId($page, 'language-picker');
    $page->assertSee('Entrar na sua conta');

    $page->click('Cadastre-se');
    waitForAuthLanguageTestId($page, 'language-picker');

    $page->assertSee('Criar conta')->assertNoJavaScriptErrors();
});
