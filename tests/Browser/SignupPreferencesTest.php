<?php

declare(strict_types=1);

function waitForSignupPreferencesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function signupPreferencesValue(mixed $page, string $testId): string
{
    return (string) $page->script("document.querySelector('[data-testid=\"{$testId}\"]').value");
}

test('the register form sends the zone, week start and clock the browser reports', function (string $locale, string $timezone, string $weekStart, string $timeFormat) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($locale)->withTimezone($timezone);
    waitForSignupPreferencesTestId($page, 'register-time-format');

    expect(signupPreferencesValue($page, 'register-timezone'))->toBe($timezone)
        ->and(signupPreferencesValue($page, 'register-week-start'))->toBe($weekStart)
        ->and(signupPreferencesValue($page, 'register-time-format'))->toBe($timeFormat);
    $page->assertNoJavaScriptErrors();
})->with([
    'United States' => ['en-US', 'America/New_York', 'sunday', '12h'],
    'Germany' => ['de-DE', 'Europe/Berlin', 'monday', '24h'],
]);

test('the google and github buttons carry the detected preferences to the OAuth start', function () {
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('login'))->withLocale('en-US')->withTimezone('Asia/Tokyo');
    waitForSignupPreferencesTestId($page, 'social-login-github');

    foreach (['social-login-google', 'social-login-github'] as $button) {
        $params = $page->script("Object.fromEntries(new URL(document.querySelector('[data-testid=\"{$button}\"]').href).searchParams)");

        expect($params)->toMatchArray(['locale' => 'en', 'timezone' => 'Asia/Tokyo', 'week_starts_on' => 'sunday', 'time_format' => '12h']);
    }

    $page->assertNoJavaScriptErrors();
});

test('the register form signs up in the browser language when the visitor picks none', function (string $browserLocale, string $expected) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($browserLocale);
    waitForSignupPreferencesTestId($page, 'register-locale');

    expect(signupPreferencesValue($page, 'register-locale'))->toBe($expected);
    $page->assertNoJavaScriptErrors();
})->with([
    'Brazil' => ['pt-BR', 'pt-BR'],
    'Portugal, same base language' => ['pt-PT', 'pt-BR'],
    'Spain' => ['es-ES', 'es'],
    'unsupported falls back to the default' => ['sv-SE', 'en'],
]);

test('the google and github buttons carry the browser language to the OAuth start', function () {
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('register'))->withLocale('es-ES');
    waitForSignupPreferencesTestId($page, 'social-login-github');

    foreach (['social-login-google', 'social-login-github'] as $button) {
        $params = $page->script("Object.fromEntries(new URL(document.querySelector('[data-testid=\"{$button}\"]').href).searchParams)");

        expect($params['locale'] ?? null)->toBe('es');
    }

    $page->assertNoJavaScriptErrors();
});
