<?php

declare(strict_types=1);

use App\Support\PinterestPinMeta;

it('seeds description from content when blank', function () {
    expect(PinterestPinMeta::seedDescription(['board_id' => '1'], 'Hello pin'))
        ->toBe(['board_id' => '1', 'description' => 'Hello pin']);
});

it('does not overwrite an existing description', function () {
    expect(PinterestPinMeta::seedDescription(['description' => 'Custom'], 'Caption'))
        ->toBe(['description' => 'Custom']);
});

it('does nothing when content is blank', function () {
    expect(PinterestPinMeta::seedDescription(['board_id' => '1'], null))
        ->toBe(['board_id' => '1'])
        ->and(PinterestPinMeta::seedDescription(['board_id' => '1'], ''))
        ->toBe(['board_id' => '1']);
});

it('truncates seeded description to 800 characters', function () {
    $long = str_repeat('a', 900);

    expect(mb_strlen(PinterestPinMeta::seedDescription([], $long)['description']))->toBe(800);
});
