<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\PreviousSoftware;

test('previous software values are stable', function () {
    expect(array_map(fn (PreviousSoftware $software): string => $software->value, PreviousSoftware::cases()))
        ->toBe(['buffer', 'hootsuite', 'later', 'metricool', 'typefully', 'hypefury', 'publer', 'postiz', 'sprout_social', 'socialbee', 'none', 'other']);
});

test('only none and other go without a brand name', function () {
    $unbranded = array_filter(PreviousSoftware::cases(), fn (PreviousSoftware $software): bool => $software->brandName() === null);

    expect(array_values($unbranded))->toBe([PreviousSoftware::None, PreviousSoftware::Other]);
});

test('the options without a brand name have a welcome label in every locale', function (string $locale) {
    foreach ([PreviousSoftware::None, PreviousSoftware::Other] as $software) {
        $key = "welcome.software.{$software->value}";

        expect(__($key, [], $locale))->not->toBe($key);
    }
})->with(Locale::values());

test('every branded option has its logo in the competitors folder', function () {
    $branded = array_filter(PreviousSoftware::cases(), fn (PreviousSoftware $software): bool => $software->brandName() !== null);

    foreach ($branded as $software) {
        expect(public_path("images/competitors/{$software->value}.svg"))->toBeFile();
    }
});
