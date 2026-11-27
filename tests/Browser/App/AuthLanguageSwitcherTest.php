<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(fn () => config(['trypost.self_hosted' => false]));

test('every logged-out auth screen offers the language switcher', function (string $route) {
    $page = visit(route($route, $route === 'password.reset' ? ['token' => 'preview-token'] : []));

    waitFor($page, '@language-picker-trigger');

    $page->assertVisible('@language-picker-trigger')->assertNoJavaScriptErrors();
})->with(['login']);

test('the switcher is hidden once the visitor is authenticated', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('verification.notice'));

    $page->assertMissing('@language-picker-trigger')->assertNoJavaScriptErrors();
});

test('the picked language survives navigating between auth screens', function () {
    $page = visit(route('login'));

    waitFor($page, '@language-picker-trigger');
    $page->click('@language-picker-trigger');
    waitFor($page, '@language-picker-option-pt-BR');
    $page->click('@language-picker-option-pt-BR');

    waitFor($page, '@language-picker-trigger');
    $page->assertSee('Entrar no TryPost');

    $page->click('@login-sign-up-link');
    waitFor($page, '@language-picker-trigger');

    $page->assertSee('Criar conta')->assertNoJavaScriptErrors();
});
