<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `tests/fixtures/media-editor-harness.js` over the real `mediaRules()`
 * payload of every content type and returns its JSON output.
 *
 * @return array<string, mixed>
 */
function runMediaEditorPresetsHarness(): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $input = tempnam(sys_get_temp_dir(), 'media-editor-presets');
    file_put_contents($input, json_encode([
        'rules' => ContentType::mediaRulesForFrontend(),
        'defaults' => ContentType::defaultCropPresets(),
    ], JSON_THROW_ON_ERROR));

    try {
        $process = (new Process([
            $node,
            base_path('tests/fixtures/media-editor-harness.js'),
            resource_path('js'),
            $input,
        ]))->mustRun();
    } finally {
        unlink($input);
    }

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

function mediaEditorPresetRatio(string $preset): float
{
    [$width, $height] = array_map('floatval', explode(':', $preset));

    return $width / $height;
}

/**
 * `resources/js/lib/mediaEditor.ts` holds no per-network preset table: it reads
 * `crop_presets` from `ContentType::mediaRules()`. The harness feeds the real
 * payload of every content type through `cropPresetsFor()` so the editor offers
 * exactly `cropPresets()`, in order, with nothing dropped by the bounds filter,
 * and every emitted ratio inside the server's bounds.
 */
test('the typescript media editor offers exactly the backend crop presets for every content type', function () {
    $output = runMediaEditorPresetsHarness();

    foreach (ContentType::cases() as $type) {
        $presets = data_get($output, "contentTypes.{$type->value}");

        expect(array_column($presets, 'value'))
            ->toBe(['freeform', 'original', ...$type->cropPresets()], $type->value);

        $bounds = $type->aspectRatioBounds();

        if ($bounds === null) {
            continue;
        }

        foreach (array_filter(array_column($presets, 'ratio'), fn ($ratio) => $ratio !== null) as $ratio) {
            expect($ratio)
                ->toBeGreaterThanOrEqual($bounds['min'] - 0.01, $type->value)
                ->toBeLessThanOrEqual($bounds['max'] + 0.01, $type->value);
        }
    }

    expect(data_get($output, 'noChannel'))->toBe(['freeform', 'original', ...ContentType::defaultCropPresets()]);
});

test('the no-channel presets sit inside every content type bounds or are filtered out', function () {
    $output = runMediaEditorPresetsHarness();

    foreach (ContentType::cases() as $type) {
        $bounds = $type->aspectRatioBounds() ?? ['min' => 0.0, 'max' => INF];
        $inside = array_values(array_filter(
            ContentType::defaultCropPresets(),
            fn (string $preset): bool => mediaEditorPresetRatio($preset) >= $bounds['min'] - 0.0001
                && mediaEditorPresetRatio($preset) <= $bounds['max'] + 0.0001,
        ));

        expect(array_column(data_get($output, "noChannelWithinBounds.{$type->value}"), 'value'))
            ->toBe(['freeform', 'original', ...$inside], $type->value);
    }
});
