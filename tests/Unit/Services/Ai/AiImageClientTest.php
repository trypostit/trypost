<?php

declare(strict_types=1);

use App\Enums\Workspace\ImageStyle;
use App\Services\Ai\AiImageClient;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;

test('generate returns null when keywords are empty', function () {
    Image::fake();

    $client = new AiImageClient;

    expect($client->generate([], ImageStyle::Cinematic))->toBeNull();
    Image::assertNothingGenerated();
});

test('generate returns raw bytes when AI succeeds', function () {
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    Image::fake([base64_encode($bytes)]);

    $client = new AiImageClient;

    expect($client->generate(['kitchen', 'morning'], ImageStyle::Illustration))
        ->toBe($bytes);
});

test('generate uses style-specific prompt prefix', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['mountain hiker'], ImageStyle::Cinematic);

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Cinematic photograph')
        && $prompt->contains('mountain hiker'));
});

test('generate maps orientation to portrait', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, orientation: 'portrait');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->isPortrait());
});

test('generate maps orientation to landscape', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, orientation: 'landscape');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->isLandscape());
});

test('generate falls back to square for unknown orientation', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, orientation: 'whatever');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->isSquare());
});

test('generate appends Brazilian Portuguese instruction when language is pt-BR', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, language: 'pt-BR');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Brazilian Portuguese'));
});

test('generate appends Spanish instruction when language is es', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, language: 'es');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Spanish'));
});

test('generate appends French instruction when language is fr', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, language: 'fr');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('French'));
});

test('generate defaults to English instruction when language is unsupported', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, language: 'sv');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('English'));
});

test('generate appends brand palette when workspace colours are provided', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(
        ['x'],
        ImageStyle::Infographic,
        brandColor: '#facc15',
        backgroundColor: '#ffffff',
        textColor: '#0f172a',
    );

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('BRAND COLOR PALETTE')
        && $prompt->contains('golden yellow')
        && $prompt->contains('charts, bars')
        && $prompt->contains('off-white')
        && $prompt->contains('in-scene typography'));
});

test('generate omits brand palette when no workspace colours are set', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic);

    Image::assertGenerated(fn (ImagePrompt $prompt) => ! $prompt->contains('BRAND COLOR PALETTE'));
});

test('generate includes only valid colours in the palette', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, brandColor: 'not-a-hex', backgroundColor: '#ffffff');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('BRAND COLOR PALETTE')
        && $prompt->contains('off-white')
        && ! $prompt->contains('Brand / primary accent'));
});

test('generate appends brand context when brandDescription is provided', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, brandDescription: 'a fitness coaching brand for busy professionals');

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Brand context')
        && $prompt->contains('fitness coaching'));
});

test('generate truncates brand description longer than 200 chars', function () {
    Image::fake();

    $longDescription = str_repeat('lorem ipsum ', 50);
    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, brandDescription: $longDescription);

    Image::assertGenerated(fn (ImagePrompt $prompt) => $prompt->contains('Brand context')
        && $prompt->contains('…'));
});

test('generate omits brand context when brandDescription is empty or whitespace', function () {
    Image::fake();

    $client = new AiImageClient;
    $client->generate(['x'], ImageStyle::Cinematic, brandDescription: '   ');

    Image::assertGenerated(fn (ImagePrompt $prompt) => ! $prompt->contains('Brand context'));
});

test('generate returns null when SDK throws', function () {
    Image::fake(fn () => throw new RuntimeException('boom'));

    $client = new AiImageClient;

    expect($client->generate(['x'], ImageStyle::Cinematic))->toBeNull();
});
