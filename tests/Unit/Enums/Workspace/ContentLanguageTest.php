<?php

declare(strict_types=1);

use App\Enums\Workspace\ContentLanguage;

test('values exposes every supported content-language code', function () {
    expect(ContentLanguage::values())->toBe([
        'en', 'pt-BR', 'es', 'fr', 'de', 'it', 'nl',
        'pl', 'el', 'ja', 'ko', 'zh', 'ru', 'tr', 'ar',
    ]);
});

test('default language is English', function () {
    expect(ContentLanguage::DEFAULT)->toBe(ContentLanguage::English);
    expect(ContentLanguage::DEFAULT->value)->toBe('en');
});

test('options pairs each code with its native and English label', function () {
    $options = ContentLanguage::options();

    expect($options)->toHaveCount(count(ContentLanguage::cases()));
    expect($options[0])->toBe(['value' => 'en', 'label' => 'English', 'englishName' => 'English']);
    expect($options)->toContain(['value' => 'pt-BR', 'label' => 'Português (Brasil)', 'englishName' => 'Brazilian Portuguese']);
    expect($options)->toContain(['value' => 'ja', 'label' => '日本語', 'englishName' => 'Japanese']);
});

test('englishName returns a distinct English name for every language', function (ContentLanguage $language, string $expected) {
    expect($language->englishName())->toBe($expected);
})->with([
    [ContentLanguage::English, 'English'],
    [ContentLanguage::PortugueseBrazil, 'Brazilian Portuguese'],
    [ContentLanguage::Spanish, 'Spanish'],
    [ContentLanguage::French, 'French'],
    [ContentLanguage::German, 'German'],
    [ContentLanguage::Italian, 'Italian'],
    [ContentLanguage::Dutch, 'Dutch'],
    [ContentLanguage::Polish, 'Polish'],
    [ContentLanguage::Greek, 'Greek'],
    [ContentLanguage::Japanese, 'Japanese'],
    [ContentLanguage::Korean, 'Korean'],
    [ContentLanguage::Chinese, 'Chinese'],
    [ContentLanguage::Russian, 'Russian'],
    [ContentLanguage::Turkish, 'Turkish'],
    [ContentLanguage::Arabic, 'Arabic'],
]);

test('only English resolves to the English name', function () {
    foreach (ContentLanguage::cases() as $language) {
        expect($language->englishName() === 'English')->toBe($language === ContentLanguage::English);
    }
});

test('label returns the native name for every language', function (ContentLanguage $language, string $expected) {
    expect($language->label())->toBe($expected);
})->with([
    [ContentLanguage::English, 'English'],
    [ContentLanguage::PortugueseBrazil, 'Português (Brasil)'],
    [ContentLanguage::Spanish, 'Español'],
    [ContentLanguage::French, 'Français'],
    [ContentLanguage::German, 'Deutsch'],
    [ContentLanguage::Italian, 'Italiano'],
    [ContentLanguage::Dutch, 'Nederlands'],
    [ContentLanguage::Polish, 'Polski'],
    [ContentLanguage::Greek, 'Ελληνικά'],
    [ContentLanguage::Japanese, '日本語'],
    [ContentLanguage::Korean, '한국어'],
    [ContentLanguage::Chinese, '中文'],
    [ContentLanguage::Russian, 'Русский'],
    [ContentLanguage::Turkish, 'Türkçe'],
    [ContentLanguage::Arabic, 'العربية'],
]);

test('direction is rtl only for Arabic', function () {
    expect(ContentLanguage::Arabic->direction())->toBe('rtl');

    foreach (ContentLanguage::cases() as $language) {
        if ($language !== ContentLanguage::Arabic) {
            expect($language->direction())->toBe('ltr');
        }
    }
});

test('fromHtmlLang resolves the two-letter primary subtag', function (string $lang, ?ContentLanguage $expected) {
    expect(ContentLanguage::fromHtmlLang($lang))->toBe($expected);
})->with([
    ['pt', ContentLanguage::PortugueseBrazil],
    ['pt-PT', ContentLanguage::PortugueseBrazil],
    ['en-US', ContentLanguage::English],
    ['es-MX', ContentLanguage::Spanish],
    ['fr', ContentLanguage::French],
    ['ja-JP', ContentLanguage::Japanese],
    ['zh-Hans', ContentLanguage::Chinese],
    ['sv', null],
    ['e', null],
    ['', null],
    // A malformed tag is matched on its whole primary subtag, never a
    // two-letter prefix — "english" must not resolve to English via "en".
    ['english', null],
]);
