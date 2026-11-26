<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;

/**
 * The editor must never offer a crop the server rejects: every preset ratio of a
 * content type sits inside that content type's aspectRatioBounds().
 */
function cropPresetRatio(string $preset): float
{
    [$width, $height] = array_map('floatval', explode(':', $preset));

    return $width / $height;
}

test('every crop preset ratio sits inside the content type aspect ratio bounds', function (ContentType $type) {
    $bounds = $type->aspectRatioBounds(MediaType::Image);

    if ($bounds === null) {
        expect($type->cropPresets())->not->toBeEmpty();

        return;
    }

    foreach ($type->cropPresets() as $preset) {
        expect(cropPresetRatio($preset->value))
            ->toBeGreaterThanOrEqual($bounds['min'] - 0.01, "{$type->value} {$preset->value}")
            ->toBeLessThanOrEqual($bounds['max'] + 0.01, "{$type->value} {$preset->value}");
    }
})->with(ContentType::cases());
