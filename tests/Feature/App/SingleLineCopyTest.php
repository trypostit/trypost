<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The longest form of a translation as it renders: every plural form, with
 * the placeholders the slots show filled in.
 */
function singleLineCopyLength(string $translation): int
{
    $values = ['count' => '12', 'total' => '7', 'network' => 'Google Business Profile', 'name' => 'Português (Brasil)'];

    return collect(explode('|', $translation))
        ->map(fn (string $form): string => (string) preg_replace('/^\s*(\{[^}]*\}|\[[^\]]*\])\s*/', '', $form))
        ->map(fn (string $form): string => (string) preg_replace_callback('/:([a-z_]+)/', fn (array $match): string => $values[$match[1]] ?? '00', $form))
        ->map(fn (string $form): int => mb_strlen($form))
        ->max();
}

test('single-line copy stays within its length in every locale', function (string $locale) {
    $budgets = require base_path('tests/fixtures/single-line-copy.php');
    $files = [];
    $tooLong = [];

    foreach ($budgets as $key => $budget) {
        $file = Str::before($key, '.');
        $files[$file] ??= require lang_path("{$locale}/{$file}.php");
        $translation = Arr::get($files[$file], Str::after($key, '.'));

        expect($translation)->toBeString("{$locale} is missing {$key}");

        $length = singleLineCopyLength($translation);

        if ($length > $budget) {
            $tooLong[] = "{$key}: {$length} > {$budget} ({$translation})";
        }
    }

    expect($tooLong)->toBe([], "{$locale} copy no longer fits its one-line slot; shorten it: ".implode('; ', $tooLong));
})->with(Locale::values());
