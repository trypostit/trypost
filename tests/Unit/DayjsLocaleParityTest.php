<?php

declare(strict_types=1);

use App\Enums\User\Locale;

/**
 * `dayjs.locale()` ignores a locale it was never given and silently keeps the
 * previous one, so a missing import shows up as dates in the wrong language
 * rather than as an error.
 */
function dayjsConfig(): string
{
    return file_get_contents(resource_path('js/dayjs.ts'));
}

test('every locale has its dayjs translations imported', function (Locale $locale) {
    $key = strtolower($locale->value);

    expect(dayjsConfig())->toContain("import 'dayjs/locale/{$key}';");
})->with(Locale::cases());

test('every locale starts its week on Monday in dayjs', function (Locale $locale) {
    $key = strtolower($locale->value);

    $line = collect(explode("\n", dayjsConfig()))
        ->first(fn (string $line) => str_contains($line, 'const weekStartMonday'));

    expect($line)->toContain("'{$key}'");
})->with(Locale::cases());
