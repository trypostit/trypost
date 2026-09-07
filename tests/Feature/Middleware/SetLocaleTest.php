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
 * @param  array<string, string>  $input
 * @param  array<string, string>  $old
 */
function runSetLocale(
    ?User $user = null,
    array $input = [],
    array $old = [],
): Response {
    $request = Request::create('/', $input === [] ? 'GET' : 'POST', $input);

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

test('uses the authenticated user locale over anything submitted', function () {
    $user = User::factory()->make(['locale' => Locale::Japanese]);

    runSetLocale(user: $user, input: ['locale' => 'fr']);

    expect(app()->getLocale())->toBe('ja');
});

test('uses the submitted locale for a guest so validation messages match the picker', function () {
    runSetLocale(input: ['locale' => 'pt-BR']);

    expect(app()->getLocale())->toBe('pt-BR');
});

test('uses the flashed old locale when the guest form is re-rendered after an error', function () {
    runSetLocale(old: ['locale' => 'es']);

    expect(app()->getLocale())->toBe('es');
});

test('falls back to the default locale when the guest submitted nothing usable', function (?string $submitted) {
    runSetLocale(input: $submitted === null ? [] : ['locale' => $submitted]);

    expect(app()->getLocale())->toBe(Locale::DEFAULT->value);
})->with([null, '', 'sv']);

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
    $response = runSetLocale();

    expect($response->headers->getCookies())->toBe([]);
});

test('passes a raw Symfony response through without crashing', function () {
    $response = (new SetLocale)->handle(
        Request::create('/', 'GET'),
        fn () => new Response('{"error":"invalid_client"}', 401, ['Content-Type' => 'application/json']),
    );

    expect($response->getStatusCode())->toBe(401);
});
