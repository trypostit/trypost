<?php

declare(strict_types=1);

use App\Models\User;

test('firstName returns the first token of the display name', function (string $name, string $expected) {
    expect(User::factory()->make(['name' => $name])->firstName())->toBe($expected);
})->with([
    ['Paulo Castellano', 'Paulo'],
    ['Ada', 'Ada'],
    ['  Marie  Curie  ', 'Marie'],
    ['', ''],
    ['   ', ''],
]);
