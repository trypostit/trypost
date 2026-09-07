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

test('options expose the code, native name and flag of every case', function () {
    expect(Locale::options())->toHaveCount(count(Locale::cases()));

    expect(Locale::options()[0])
        ->toMatchArray(['code' => 'en', 'name' => 'English'])
        ->and(Locale::options()[0]['flag'])->toEndWith('/images/flags/US.svg');
});

test('every case points at a flag file that actually ships', function (Locale $locale) {
    expect(public_path("images/flags/{$locale->flag()}.svg"))->toBeFile();
})->with(Locale::cases());
