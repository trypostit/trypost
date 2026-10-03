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

test('englishName returns the English name of every locale', function (Locale $locale, string $expected) {
    expect($locale->englishName())->toBe($expected);
})->with([
    [Locale::English, 'English'],
    [Locale::Ukrainian, 'Ukrainian'],
    [Locale::PortugueseBrazil, 'Brazilian Portuguese'],
    [Locale::Spanish, 'Spanish'],
    [Locale::French, 'French'],
    [Locale::German, 'German'],
    [Locale::Italian, 'Italian'],
    [Locale::Dutch, 'Dutch'],
    [Locale::Polish, 'Polish'],
    [Locale::Greek, 'Greek'],
    [Locale::Japanese, 'Japanese'],
    [Locale::Korean, 'Korean'],
    [Locale::Chinese, 'Chinese'],
    [Locale::Russian, 'Russian'],
    [Locale::Turkish, 'Turkish'],
    [Locale::Arabic, 'Arabic'],
]);

test('bcp47 widens only the codes Google treats as underspecified', function (Locale $locale, string $expected) {
    expect($locale->bcp47())->toBe($expected);
})->with([
    [Locale::English, 'en'],
    [Locale::PortugueseBrazil, 'pt-BR'],
    [Locale::Chinese, 'zh-CN'],
]);

test('promptLanguage names the language and its code', function () {
    expect(Locale::PortugueseBrazil->promptLanguage())->toBe('Brazilian Portuguese (pt-BR)');
});
