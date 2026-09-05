<?php

declare(strict_types=1);

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Support\Repurpose\Templates;

/**
 * The repurpose screens build translation keys from enum values, so a new case
 * without a string renders the raw key to the user instead of failing loudly.
 */
function repurposeStrings(string $locale): array
{
    return require dirname(__DIR__, 3)."/lang/{$locale}/repurposes.php";
}

dataset('locales', fn () => array_map(
    fn (string $path): string => basename($path),
    array_filter(glob(dirname(__DIR__, 3).'/lang/*'), 'is_dir'),
));

test('every enum value the interface interpolates has a string', function (string $locale) {
    $strings = repurposeStrings($locale);

    foreach (Status::cases() as $status) {
        expect(data_get($strings, "status.{$status->value}"))->not->toBeNull()
            ->and(data_get($strings, "status_card.{$status->value}_hint"))->not->toBeNull();
    }

    foreach (ItemStatus::cases() as $status) {
        expect(data_get($strings, "items.statuses.{$status->value}"))->not->toBeNull();
    }

    foreach (ItemReason::cases() as $reason) {
        expect(data_get($strings, "items.reasons.{$reason->value}"))->not->toBeNull();
    }

    foreach (SourceFormat::cases() as $format) {
        expect(data_get($strings, "formats.{$format->value}"))->not->toBeNull();
    }

    foreach (Templates::all() as $template) {
        expect(data_get($strings, "templates.{$template['key']}.title"))->not->toBeNull()
            ->and(data_get($strings, "templates.{$template['key']}.description"))->not->toBeNull();
    }
})->with('locales');
