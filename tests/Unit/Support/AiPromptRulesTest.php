<?php

declare(strict_types=1);

use App\Support\AiPromptRules;

test('wordCount counts words and each CJK character', function (string $text, int $expected) {
    expect(AiPromptRules::wordCount($text))->toBe($expected);
})->with([
    'plain words' => ['write a post now', 4],
    'extra whitespace' => ['  two   words ', 2],
    'empty' => ['', 0],
    'japanese' => ['新商品を紹介', 6],
    'mixed latin and japanese' => ['Launch 新商品', 4],
    'accented portuguese' => ['nova coleção de verão', 4],
]);
