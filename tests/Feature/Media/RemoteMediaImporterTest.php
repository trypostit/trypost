<?php

declare(strict_types=1);

use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Support\HeicConverter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->workspace = Workspace::factory()->create();
    $this->importer = app(RemoteMediaImporter::class);
});

afterEach(function () {
    HeicConverter::flush();
});

function importerFixture(string $name): string
{
    return file_get_contents(base_path("tests/fixtures/{$name}"));
}

test('imports each type as a temporary upload with its source meta', function (string $body, string $filename, MediaType $type, string $mime) {
    Http::fake(['https://93.184.216.34/*' => Http::response($body, 200, ['Content-Type' => 'application/octet-stream'])]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile(
            'https://93.184.216.34/file',
            $filename,
            allowedHosts: ['93.184.216.34'],
            source: Source::GoogleDrive,
            sourceMeta: ['file_id' => 'abc123'],
        ),
        MediaType::cases(),
        30,
    );

    expect($result->succeeded())->toBeTrue()
        ->and($result->failure)->toBeNull();

    $media = Media::query()->sole();

    expect($media->id)->toBe($result->media->id)
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->upload_token)->not->toBeNull()
        ->and($media->type)->toBe($type)
        ->and($media->mime_type)->toBe($mime)
        ->and($media->original_filename)->toBe($filename)
        ->and(data_get($media->meta, 'source'))->toBe('google_drive')
        ->and(data_get($media->meta, 'source_meta'))->toEqual(['file_id' => 'abc123']);

    Storage::assertExists($media->path);
})->with([
    'png' => fn () => [importerFixture('1x1.png'), 'photo.png', MediaType::Image, 'image/png'],
    'mp4' => fn () => [importerFixture('sample.mp4'), 'clip.mp4', MediaType::Video, 'video/mp4'],
    'pdf' => fn () => ["%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", 'deck.pdf', MediaType::Document, 'application/pdf'],
]);

test('a host outside the allowlist fails without a request', function () {
    Http::fake();

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/photo.png', 'photo.png', allowedHosts: ['images.unsplash.com', '*.googleusercontent.com']),
        MediaType::cases(),
        30,
    );

    expect($result->succeeded())->toBeFalse()
        ->and($result->failure)->toBe(ImportedFile::HOST_NOT_ALLOWED)
        ->and(Media::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('a wildcard host matches subdomains only', function () {
    config(['trypost.security.allow_private_network' => true]);
    Http::fake(['https://lh3.googleusercontent.com/*' => Http::response(importerFixture('1x1.png'))]);

    $allowed = ['*.googleusercontent.com'];

    $ok = $this->importer->import($this->workspace, new RemoteFile('https://lh3.googleusercontent.com/a', 'a.png', allowedHosts: $allowed), MediaType::cases(), 30);
    $lookalike = $this->importer->import($this->workspace, new RemoteFile('https://googleusercontent.com.evil.test/a', 'a.png', allowedHosts: $allowed), MediaType::cases(), 30);

    expect($ok->succeeded())->toBeTrue()
        ->and($lookalike->failure)->toBe(ImportedFile::HOST_NOT_ALLOWED);

    Http::assertSentCount(1);
});

test('a redirect to a disallowed host fails and the second host is never requested', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'https://93.184.216.35/photo.png']),
        'https://93.184.216.35/*' => Http::response(importerFixture('1x1.png')),
    ]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/start', 'photo.png', allowedHosts: ['93.184.216.34']),
        MediaType::cases(),
        30,
        followRedirects: true,
    );

    expect($result->failure)->toBe(ImportedFile::HOST_NOT_ALLOWED)
        ->and(Media::query()->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '93.184.216.35'));
});

test('a redirect within the allowlist is followed', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'https://93.184.216.35/photo.png']),
        'https://93.184.216.35/*' => Http::response(importerFixture('1x1.png')),
    ]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/start', 'photo.png', allowedHosts: ['93.184.216.34', '93.184.216.35']),
        MediaType::cases(),
        30,
        followRedirects: true,
    );

    expect($result->succeeded())->toBeTrue();
});

test('redirects are refused unless asked for, and the target is never requested', function () {
    Http::fake([
        'https://93.184.216.34/start' => Http::response('', 302, ['Location' => 'https://93.184.216.35/photo.png']),
        'https://93.184.216.35/*' => Http::response(importerFixture('1x1.png')),
    ]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/start', 'photo.png'),
        MediaType::cases(),
        30,
    );

    expect($result->failure)->toBe(ImportedFile::UNREACHABLE)
        ->and(Media::query()->count())->toBe(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '93.184.216.35'));
});

test('a transfer that runs past its deadline fails as unreachable and leaves no row or temp file', function () {
    Http::fake([
        'https://93.184.216.34/start' => function () {
            $this->travel(5)->minutes();

            return Http::response('', 302, ['Location' => 'https://93.184.216.34/photo.png']);
        },
        'https://93.184.216.34/photo.png' => Http::response(importerFixture('1x1.png')),
    ]);
    $before = glob(sys_get_temp_dir().'/media_*') ?: [];

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/start', 'photo.png'),
        MediaType::cases(),
        30,
        now()->addMinute(),
        followRedirects: true,
    );

    expect($result->failure)->toBe(ImportedFile::UNREACHABLE)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([])
        ->and(glob(sys_get_temp_dir().'/media_*') ?: [])->toBe($before);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/photo.png'));
});

test('a transfer that ends past its deadline stores nothing', function () {
    Http::fake([
        'https://93.184.216.34/*' => function () {
            $this->travel(5)->minutes();

            return Http::response(importerFixture('1x1.png'));
        },
    ]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/photo.png', 'photo.png'),
        MediaType::cases(),
        30,
        now()->addMinute(),
    );

    expect($result->failure)->toBe(ImportedFile::UNREACHABLE)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

test('a private address fails without a request', function () {
    Http::fake();

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('http://10.0.0.1/photo.png', 'photo.png'),
        MediaType::cases(),
        30,
    );

    expect($result->failure)->toBe(ImportedFile::UNREACHABLE)
        ->and(Media::query()->count())->toBe(0);

    Http::assertNothingSent();
});

test('HEIC bytes labelled as PNG are refused while conversion is off', function () {
    config(['trypost.media.heic_conversion' => false]);
    HeicConverter::flush();
    Http::fake(['https://93.184.216.34/*' => Http::response(importerFixture('sample.heic'), 200, ['Content-Type' => 'image/png'])]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/photo.png', 'photo.png'),
        MediaType::cases(),
        30,
    );

    expect($result->failure)->toBe(ImportedFile::TYPE_NOT_ALLOWED)
        ->and(Media::query()->count())->toBe(0);
});

test('a type outside the allowed types is refused', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response(importerFixture('sample.mp4'))]);

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/clip.mp4', 'clip.mp4'),
        [MediaType::Image],
        30,
    );

    expect($result->failure)->toBe(ImportedFile::TYPE_NOT_ALLOWED);
});

test('an image over its cap fails and leaves no row, stored file or temp file', function () {
    config(['trypost.media.max_size_mb.image' => 1]);
    Http::fake(['https://93.184.216.34/*' => Http::response(importerFixture('1x1.png').str_repeat('0', 1024 * 1024 + 1))]);
    $before = glob(sys_get_temp_dir().'/media_*') ?: [];

    $result = $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/big.png', 'big.png'),
        MediaType::cases(),
        30,
    );

    expect($result->failure)->toBe(ImportedFile::TOO_LARGE)
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([])
        ->and(glob(sys_get_temp_dir().'/media_*') ?: [])->toBe($before);
});

test('an unsuccessful response is unreachable', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response('nope', 404)]);

    $result = $this->importer->import($this->workspace, new RemoteFile('https://93.184.216.34/x.png', 'x.png'), MediaType::cases(), 30);

    expect($result->failure)->toBe(ImportedFile::UNREACHABLE);
});

test('custom headers are sent', function () {
    Http::fake(['https://93.184.216.34/*' => Http::response(importerFixture('1x1.png'))]);

    $this->importer->import(
        $this->workspace,
        new RemoteFile('https://93.184.216.34/photo.png', 'photo.png', ['Authorization' => 'Bearer secret-token']),
        MediaType::cases(),
        30,
    );

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer secret-token'));
});

test('the filename is basenamed, capped at 255 characters and falls back to the MIME extension', function (string $given, string $expected) {
    Http::fake(['https://93.184.216.34/*' => Http::response(importerFixture('1x1.png'))]);

    $media = $this->importer->import($this->workspace, new RemoteFile('https://93.184.216.34/x', $given), MediaType::cases(), 30)->media;

    expect($media->original_filename)->toBe($expected);
})->with([
    'path segments' => ['../../etc/photo.png', 'photo.png'],
    'empty' => ['', 'download.png'],
    'too long' => [str_repeat('a', 300).'.png', str_repeat('a', 251).'.png'],
]);
