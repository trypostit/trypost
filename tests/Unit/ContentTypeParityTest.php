<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * @return array{values: list<string>, polls: list<string>, poll_posts: array<string, string>, medialess: list<string>}
 */
function composerContentTypes(): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $process = (new Process([$node, base_path('tests/fixtures/content-type-harness.ts'), resource_path('js')]))->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('the composer knows every content type the server has', function () {
    expect(composerContentTypes()['values'])
        ->toEqualCanonicalizing(array_map(fn (ContentType $type): string => $type->value, ContentType::cases()));
});

test('the composer treats as polls the content types the server does', function () {
    $composer = composerContentTypes();
    $polls = collect(ContentType::cases())->filter(fn (ContentType $type): bool => $type->isPoll());

    expect($composer['polls'])
        ->toEqualCanonicalizing($polls->map(fn (ContentType $type): string => $type->value)->values()->all())
        ->and($composer['poll_posts'])
        ->toEqual($polls->mapWithKeys(fn (ContentType $type): array => [$type->value => ContentType::defaultFor($type->platform())->value])->all());
});

test('the composer takes no media on the content types the server takes none on', function () {
    $medialess = collect(ContentType::cases())
        ->filter(fn (ContentType $type): bool => $type->maxMediaCount() === 0 && ! $type->isCaptionless())
        ->map(fn (ContentType $type): string => $type->value)
        ->values()
        ->all();

    expect(composerContentTypes()['medialess'])->toEqualCanonicalizing($medialess);
});
