<?php

declare(strict_types=1);

use App\Support\Hashtags;

test('counts hashtags the way instagram links them', function (string $text, int $expected) {
    expect(Hashtags::count($text))->toBe($expected);
})->with([
    'none' => ['Plain caption', 0],
    'six' => ['Testing preview #reference https://example.com #a #b #c #d #e onlyX', 6],
    'unicode' => ['#café #über', 2],
    'digits only is not a tag' => ['Top #1 and #2026', 0],
    'digits with a letter' => ['#2026goals', 1],
    'url fragment is not a tag' => ['https://example.com/#section', 0],
    'html entity is not a tag' => ['Fish &#38; chips', 0],
    'repeated tags all count' => ['#a #a #a', 3],
    'start of text' => ['#first word', 1],
]);

test('keeps only the first hashtags up to the limit', function (string $text, int $limit, string $expected) {
    expect(Hashtags::keepFirst($text, $limit))->toBe($expected);
})->with([
    'under the limit' => ['Launch #a #b', 5, 'Launch #a #b'],
    'trailing extras' => ['Launch #a #b #c #d #e #f #g', 5, 'Launch #a #b #c #d #e'],
    'extras mid text' => ["#a #b day one #c\nmore text", 2, "#a #b day one\nmore text"],
    'after punctuation' => ['Go!#a (#b) #c', 1, 'Go!#a ()'],
    'url fragments and digits stay' => ['#a https://example.com/#b #2026 #c', 1, '#a https://example.com/#b #2026'],
]);
