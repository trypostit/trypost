<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Enums\User\PreviousSoftware;

test('previous software values are stable', function () {
    expect(array_map(fn (PreviousSoftware $software): string => $software->value, PreviousSoftware::cases()))
        ->toBe(['buffer', 'hootsuite', 'later', 'metricool', 'typefully', 'hypefury', 'publer', 'postiz', 'sprout_social', 'socialbee', 'none', 'other']);
});

test('every previous software has a welcome label in every locale', function (string $locale) {
    foreach (PreviousSoftware::cases() as $software) {
        $key = "welcome.software.{$software->value}";

        expect(__($key, [], $locale))->not->toBe($key);
    }
})->with(Locale::values());
