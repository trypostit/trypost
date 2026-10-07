<?php

declare(strict_types=1);

use App\Enums\User\Locale;

function waitForAuthTitlesTestId(mixed $page, string $testId): void
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

function waitForAuthTitlesRequirementMet(mixed $page, string $key, string $met): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="password-requirement-{$key}"][data-met="{$met}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                if (document.querySelector(selector)) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

beforeEach(fn () => config(['trypost.self_hosted' => false]));

test('login shows the new title without a description and links to register', function () {
    $page = visit(route('login'));
    waitForAuthTitlesTestId($page, 'login-sign-up-link');

    $page->assertSeeIn('@auth-title', 'Log in to TryPost')
        ->assertDontSee('Enter your email and password below to log in')
        ->click('@login-sign-up-link');

    waitForAuthTitlesTestId($page, 'register-log-in-link');

    $page->assertSeeIn('@auth-title', 'Create a TryPost account')
        ->assertDontSee('Create your account and start scheduling posts across every network.')
        ->click('@register-log-in-link');

    waitForAuthTitlesTestId($page, 'login-sign-up-link');

    $page->assertSeeIn('@auth-title', 'Log in to TryPost')->assertNoJavaScriptErrors();
});

test('typing a password ticks each requirement', function () {
    $page = visit(route('register'));
    waitForAuthTitlesTestId($page, 'password-requirements');

    $page->assertSeeIn('@password-requirement-min', 'At least 12 characters')
        ->assertAttribute('@password-requirement-min', 'data-met', 'false')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@register-password', 'abc');
    waitForAuthTitlesRequirementMet($page, 'mixed_case', 'false');
    $page->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false');

    $page->type('@register-password', 'abcD');
    waitForAuthTitlesRequirementMet($page, 'mixed_case', 'true');
    $page->assertAttribute('@password-requirement-mixed_case', 'data-met', 'true')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false');

    $page->type('@register-password', 'abcD1');
    waitForAuthTitlesRequirementMet($page, 'number', 'true');
    $page->assertAttribute('@password-requirement-number', 'data-met', 'true')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@register-password', 'abcD1!');
    waitForAuthTitlesRequirementMet($page, 'symbol', 'true');
    $page->assertAttribute('@password-requirement-symbol', 'data-met', 'true')
        ->assertAttribute('@password-requirement-min', 'data-met', 'false');

    $page->type('@register-password', 'abcD1!abcdef');
    waitForAuthTitlesRequirementMet($page, 'min', 'true');
    $page->assertAttribute('@password-requirement-min', 'data-met', 'true')
        ->assertNoJavaScriptErrors();
});

test('the auth title fits one line on a phone in every language', function (string $route, string $key) {
    $page = visit(route($route))->resize(390, 844);
    waitForAuthTitlesTestId($page, 'auth-title');

    $translations = collect(Locale::cases())
        ->mapWithKeys(fn (Locale $locale): array => [$locale->value => __($key, [], $locale->value)])
        ->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const element = document.querySelector('[data-testid="auth-title"]');
            const lineHeight = parseFloat(getComputedStyle(element).lineHeight);
            return Object.entries(translations)
                .filter(([, text]) => {
                    element.textContent = text;
                    return Math.round(element.getBoundingClientRect().height / lineHeight) > 1;
                })
                .map(([locale]) => locale);
        })()
    JS);

    expect($wrapped)->toBe([]);
})->with([
    'login' => ['login', 'auth.login.title'],
    'register' => ['register', 'auth.register.title'],
]);

test('every password requirement fits one line on a phone in every language', function () {
    $page = visit(route('register'))->resize(390, 844);
    waitForAuthTitlesTestId($page, 'password-requirements');

    $translations = collect(Locale::cases())
        ->flatMap(fn (Locale $locale): array => collect(['min', 'mixed_case', 'number', 'symbol'])
            ->map(fn (string $key): string => __("auth.register.password_requirements.{$key}", ['count' => 12], $locale->value))
            ->all())
        ->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const element = document.querySelector('[data-testid="password-requirement-min"] span:last-child');
            const lineHeight = parseFloat(getComputedStyle(element).lineHeight);
            return translations.filter((text) => {
                element.textContent = text;
                return Math.round(element.getBoundingClientRect().height / lineHeight) > 1;
            });
        })()
    JS);

    expect($wrapped)->toBe([]);
});
