<?php

declare(strict_types=1);

use Illuminate\Support\Uri;

test('the register form sends the zone, week start and clock the browser reports', function (string $locale, string $timezone, string $weekStart, string $timeFormat) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($locale)->withTimezone($timezone);
    waitForState($page, '@register-time-format', "element.value !== ''");

    $page->assertValue('@register-timezone', $timezone)
        ->assertValue('@register-week-start', $weekStart)
        ->assertValue('@register-time-format', $timeFormat)
        ->assertNoJavaScriptErrors();
})->with([
    'United States' => ['en-US', 'America/New_York', 'sunday', '12h'],
    'Germany' => ['de-DE', 'Europe/Berlin', 'monday', '24h'],
]);

test('the google and github buttons carry the detected preferences to the OAuth start', function () {
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('login'))->withLocale('en-US')->withTimezone('Asia/Tokyo');
    waitFor($page, '@social-login-github');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->all())
            ->toMatchArray(['locale' => 'en', 'timezone' => 'Asia/Tokyo', 'week_starts_on' => 'sunday', 'time_format' => '12h']);
    }

    $page->assertNoJavaScriptErrors();
});

test('the register form signs up in the browser language when the visitor picks none', function (string $browserLocale, string $expected) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($browserLocale);
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->assertValue('@register-locale', $expected)
        ->assertNoJavaScriptErrors();
})->with([
    'Brazil' => ['pt-BR', 'pt-BR'],
    'Portugal, same base language' => ['pt-PT', 'pt-BR'],
    'Spain' => ['es-ES', 'es'],
    'unsupported falls back to the default' => ['sv-SE', 'en'],
]);

test('the google and github buttons carry the browser language to the OAuth start', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('register'))->withLocale('es-ES');
    waitFor($page, '@social-login-github');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->get('locale'))->toBe('es');
    }

    $page->assertNoJavaScriptErrors();
});
