<?php

declare(strict_types=1);

use App\Support\Analytics\Pdf\PdfImages;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

test('our own media is read from the disk and shrunk into a data URI', function () {
    Storage::put('medias/photo.png', UploadedFile::fake()->image('photo.png', 800, 600)->getContent());
    Http::fake();

    $image = app(PdfImages::class)->stored('medias/photo.png', 96);

    expect($image)->toStartWith('data:image/jpeg;base64,');
    [$width, $height] = getimagesizefromstring(base64_decode(substr($image, strlen('data:image/jpeg;base64,'))));
    expect([$width, $height])->toBe([96, 96]);
    Http::assertNothingSent();
});

test('a missing file or bytes that are not an image are left out', function () {
    Storage::put('medias/notes.txt', 'not an image');

    expect(app(PdfImages::class)->stored('medias/missing.png', 96))->toBeNull()
        ->and(app(PdfImages::class)->stored('medias/notes.txt', 96))->toBeNull()
        ->and(app(PdfImages::class)->stored(null, 96))->toBeNull();
});

test('a remote image is fetched once per size and inlined', function () {
    fakePublicDns();
    Http::fake(['cdn.example.com/*' => Http::response(UploadedFile::fake()->image('avatar.png', 200, 200)->getContent(), 200, ['Content-Type' => 'image/png'])]);
    $images = app(PdfImages::class);

    expect($images->remote('https://cdn.example.com/avatar.png', 72))->toStartWith('data:image/jpeg;base64,')
        ->and($images->remote('https://cdn.example.com/avatar.png', 72))->toStartWith('data:image/jpeg;base64,');

    Http::assertSentCount(1);
});

test('a remote image on a private address is never requested', function () {
    Http::fake();

    expect(app(PdfImages::class)->remote('http://169.254.169.254/latest/meta-data', 72))->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
});

test('remote images stop being fetched once the shared deadline has passed', function () {
    fakePublicDns();
    Http::fake(['cdn.example.com/*' => Http::response(UploadedFile::fake()->image('avatar.png', 200, 200)->getContent(), 200, ['Content-Type' => 'image/png'])]);
    $images = app(PdfImages::class);

    expect($images->remote('https://cdn.example.com/first.png', 72))->toStartWith('data:image/jpeg;base64,');

    $this->travel(61)->seconds();

    expect($images->remote('https://cdn.example.com/second.png', 72))->toBeNull();
    Http::assertSentCount(1);
});

test('our own images are downloaded together once and later reads use them', function () {
    Http::fake(['cdn.example.com/*' => Http::response(UploadedFile::fake()->image('photo.png', 800, 1000)->getContent(), 200, ['Content-Type' => 'image/png'])]);
    $images = app(PdfImages::class);

    $images->warm(['https://cdn.example.com/a.png', 'https://cdn.example.com/b.png', 'https://cdn.example.com/a.png'], 96);

    expect($images->remote('https://cdn.example.com/a.png', 96))->toStartWith('data:image/jpeg;base64,')
        ->and($images->remote('https://cdn.example.com/b.png', 96))->toStartWith('data:image/jpeg;base64,');
    Http::assertSentCount(2);
});
