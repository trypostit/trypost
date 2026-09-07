<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Http\Middleware\App\SetLocale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Run the middleware over a request built from the given ingredients, so the
 * shared `htmlDir` and the active locale can be asserted.
 *
 * @param  array<string, string>  $input
 * @param  array<string, string>  $old
 */
function runSetLocale(
    ?User $user = null,
    ?string $acceptLanguage = null,
    array $input = [],
    array $old = [],
): Response {
    $request = Request::create('/', $input === [] ? 'GET' : 'POST', $input);

    if ($acceptLanguage !== null) {
        $request->headers->set('Accept-Language', $acceptLanguage);
    }

    if ($old !== []) {
        $session = new Store('test', new ArraySessionHandler(60));
        $session->flashInput($old);
        $request->setLaravelSession($session);
    }

    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    return (new SetLocale)->handle($request, fn () => response('ok'));
}

test('uses the authenticated user locale over anything on the request', function () {
    $user = User::factory()->make(['locale' => Locale::Japanese]);

    runSetLocale(user: $user, acceptLanguage: 'de-DE,de;q=0.9', input: ['locale' => 'fr']);

    expect(app()->getLocale())->toBe('ja');
});

test('falls back to the default locale when the user has none stored', function () {
    $user = User::factory()->make(['locale' => null]);

    runSetLocale(user: $user);

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value);
});

test('uses the submitted locale for a guest so validation messages match the picker', function () {
    runSetLocale(acceptLanguage: 'en-US', input: ['locale' => 'pt-BR']);

    expect(app()->getLocale())->toBe('pt-BR');
});

test('uses the flashed old locale when the guest form is re-rendered after an error', function () {
    runSetLocale(acceptLanguage: 'en-US', old: ['locale' => 'es']);

    expect(app()->getLocale())->toBe('es');
});

test('negotiates the guest locale from the Accept-Language header', function (string $header, string $expected) {
    runSetLocale(acceptLanguage: $header);

    expect(app()->getLocale())->toBe($expected);
})->with([
    'exact match' => ['pt-BR,pt;q=0.9,en;q=0.8', 'pt-BR'],
    'primary subtag' => ['pt-PT,pt;q=0.9', 'pt-BR'],
    'quality ordering' => ['xx;q=1.0,de;q=0.9,en;q=0.8', 'de'],
    'underscore separator' => ['ja_JP', 'ja'],
    'right to left' => ['ar-SA,ar;q=0.9', 'ar'],
]);

test('falls back to the default locale when nothing on the request is supported', function (?string $header) {
    runSetLocale(acceptLanguage: $header);

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value);
})->with([null, '', '*', 'sv-SE,sv;q=0.9']);

test('ignores an unsupported submitted locale', function () {
    runSetLocale(acceptLanguage: 'de-DE', input: ['locale' => 'sv']);

    expect(app()->getLocale())->toBe('de');
});

test('shares rtl direction for Arabic and ltr for every other locale', function (string $locale, string $direction) {
    runSetLocale(user: User::factory()->make(['locale' => $locale]));

    expect(View::shared('htmlDir'))->toBe($direction);
})->with([
    ['ar', 'rtl'],
    ['en', 'ltr'],
    ['uk', 'ltr'],
    ['ja', 'ltr'],
    ['pt-BR', 'ltr'],
]);

test('no longer writes a locale cookie', function () {
    $response = runSetLocale(acceptLanguage: 'sv-SE');

    expect($response->headers->getCookies())->toBe([]);
});

test('passes a raw Symfony response through without crashing', function () {
    $response = (new SetLocale)->handle(
        Request::create('/', 'GET'),
        fn () => new Response('{"error":"invalid_client"}', 401, ['Content-Type' => 'application/json']),
    );

    expect($response->getStatusCode())->toBe(401);
});
