<?php

declare(strict_types=1);

use App\Rules\SingleEmoji;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('every emoji the picker offers is a valid reaction', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $process = (new Process([$node, base_path('tests/fixtures/emoji-picker-harness.ts'), resource_path('js')]))->mustRun();
    $emojis = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $refused = collect($emojis)
        ->reject(fn (string $emoji): bool => Validator::make(['emoji' => $emoji], ['emoji' => [new SingleEmoji]])->passes())
        ->values()
        ->all();

    expect($emojis)->not->toBeEmpty()
        ->and($refused)->toBe([]);
});
