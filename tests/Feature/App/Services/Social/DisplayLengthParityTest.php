<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Services\Social\ContentSanitizer;
use Symfony\Component\Process\Process;

test('the composer counts plain text the way the server measures it on every network', function () {
    $corpusPath = base_path('tests/fixtures/display-length-corpus.json');
    $corpus = json_decode(file_get_contents($corpusPath), true, flags: JSON_THROW_ON_ERROR);
    $platforms = array_map(fn (Platform $platform): string => $platform->value, Platform::cases());
    $platformsPath = tempnam(sys_get_temp_dir(), 'platforms');
    file_put_contents($platformsPath, json_encode($platforms, JSON_THROW_ON_ERROR));

    $process = new Process([
        'node',
        base_path('tests/fixtures/display-length-harness.ts'),
        resource_path('js'),
        $corpusPath,
        $platformsPath,
    ]);
    $process->run();
    unlink($platformsPath);

    if (! $process->isSuccessful()) {
        $this->markTestSkipped('node is unavailable: '.$process->getErrorOutput());
    }

    $sanitizer = app(ContentSanitizer::class);
    $fromPhp = collect(Platform::cases())->mapWithKeys(fn (Platform $platform): array => [
        $platform->value => array_combine($corpus, array_map(fn (string $text): int => mb_strlen($sanitizer->displayText($text, $platform)), $corpus)),
    ])->all();
    $fromTypeScript = collect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))
        ->map(fn (array $counts): array => array_combine($corpus, $counts))
        ->all();

    expect($fromTypeScript)->toEqual($fromPhp);
});
