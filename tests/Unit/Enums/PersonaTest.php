<?php

declare(strict_types=1);

use App\Enums\User\Persona;
use App\Enums\Workspace\ContentLanguage;

test('persona values are stable', function () {
    expect(array_map(fn (Persona $persona): string => $persona->value, Persona::cases()))
        ->toBe(['creator', 'freelancer', 'developer', 'startup', 'agency', 'small_business', 'marketer', 'online_store', 'other']);
});

test('every persona has a welcome label in every locale', function (string $locale) {
    foreach (Persona::cases() as $persona) {
        $key = "welcome.personas.{$persona->value}";

        expect(__($key, [], $locale))->not->toBe($key);
    }
})->with(ContentLanguage::values());
