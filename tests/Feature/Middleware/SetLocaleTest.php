<?php

declare(strict_types=1);

use App\Http\Middleware\App\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run the middleware with the given `locale` cookie (null = no cookie) and
 * return the response, whose shared `htmlDir` and queued cookies can be asserted.
 */
function setLocaleResponse(?string $locale): Response
{
    $request = Request::create('/', 'GET');

    if ($locale !== null) {
        $request->cookies->set('locale', $locale);
    }

    return (new SetLocale)->handle($request, fn () => response('ok'));
}

/** The shared `htmlDir` the Blade root view renders into `<html dir>`. */
function runSetLocale(?string $locale): string
{
    setLocaleResponse($locale);

    return View::shared('htmlDir');
}

test('shares rtl direction and sets the locale for Arabic', function () {
    expect(runSetLocale('ar'))->toBe('rtl');
    expect(app()->getLocale())->toBe('ar');
});

test('shares ltr direction for a left-to-right locale', function (string $locale) {
    expect(runSetLocale($locale))->toBe('ltr');
    expect(app()->getLocale())->toBe($locale);
})->with(['en', 'ja', 'pt-BR', 'de']);

test('falls back to the default locale and ltr for an unknown cookie', function () {
    expect(runSetLocale('sv'))->toBe('ltr');
    expect(app()->getLocale())->toBe(config('languages.default'));
});

test('falls back to the default locale and ltr when no cookie is set', function () {
    expect(runSetLocale(null))->toBe('ltr');
    expect(app()->getLocale())->toBe(config('languages.default'));
});

test('persists the default locale cookie when the incoming cookie is invalid or absent', function (?string $locale) {
    $cookie = collect(setLocaleResponse($locale)->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'locale');

    expect($cookie)->not->toBeNull();
    expect($cookie->getValue())->toBe(config('languages.default'));
})->with(['sv', null]);

test('does not reset the cookie when a valid locale is present', function () {
    $cookie = collect(setLocaleResponse('ar')->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'locale');

    expect($cookie)->toBeNull();
});
