<?php

declare(strict_types=1);

use App\Enums\User\Locale;

test('every case ships a translation directory', function (Locale $locale) {
    expect(is_dir(lang_path($locale->value)))->toBeTrue();
})->with(Locale::cases());

test('every case has a non-empty native label', function (Locale $locale) {
    expect($locale->label())->not->toBe('');
})->with(Locale::cases());

test('only Arabic is right to left', function () {
    foreach (Locale::cases() as $locale) {
        expect($locale->direction())->toBe($locale === Locale::Arabic ? 'rtl' : 'ltr');
    }
});

test('options expose the code, native name, direction and flag of every case', function () {
    expect(Locale::options())->toHaveCount(count(Locale::cases()));

    expect(Locale::options()[0])
        ->toMatchArray(['code' => 'en', 'name' => 'English', 'dir' => 'ltr'])
        ->and(Locale::options()[0]['flag'])->toEndWith('/images/flags/US.svg');
});

test('every case points at a flag file that actually ships', function (Locale $locale) {
    expect(public_path("images/flags/{$locale->flag()}.svg"))->toBeFile();
})->with(Locale::cases());

test('resolves a language tag to a supported locale', function (string $tag, ?Locale $expected) {
    expect(Locale::fromTag($tag))->toBe($expected);
})->with([
    'exact' => ['pt-BR', Locale::PortugueseBrazil],
    'case insensitive' => ['PT-br', Locale::PortugueseBrazil],
    'underscore separator' => ['pt_BR', Locale::PortugueseBrazil],
    'region falls back to the primary subtag' => ['pt-PT', Locale::PortugueseBrazil],
    'script subtag' => ['zh-Hans', Locale::Chinese],
    'plain primary subtag' => ['de', Locale::German],
    'unsupported language' => ['sv-SE', null],
    'too short to be a language' => ['x', null],
    'empty' => ['', null],
]);

test('negotiates an Accept-Language header down to a supported locale', function (?string $header, ?Locale $expected) {
    expect(Locale::fromAcceptLanguage($header))->toBe($expected);
})->with([
    'highest quality wins' => ['fr;q=0.4,de;q=0.9,en;q=0.8', Locale::German],
    'order breaks a quality tie' => ['it,nl', Locale::Italian],
    'implicit quality outranks an explicit one' => ['ja;q=0.5,ko', Locale::Korean],
    'skips unsupported languages' => ['sv-SE,sv;q=0.9,el;q=0.5', Locale::Greek],
    'ignores the wildcard' => ['*', null],
    'no supported language' => ['sv-SE,da;q=0.8', null],
    'empty header' => ['', null],
    'absent header' => [null, null],
]);
