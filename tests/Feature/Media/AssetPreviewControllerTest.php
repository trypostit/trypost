<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\AssetPreviewUrlFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function previewAsset(Workspace $workspace, array $attributes = []): Media
{
    return Media::factory()->create(array_merge([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
        'path' => 'media/preview.jpg',
        'mime_type' => 'image/jpeg',
        'size' => 12,
    ], $attributes));
}

test('signed preview route streams a current workspace asset without exposing paths', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/preview.jpg', 'preview-bytes');
    $workspace = Workspace::factory()->create();
    $media = previewAsset($workspace);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($media, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($preview['url'])
        ->assertOk()
        ->assertStreamed()
        ->assertStreamedContent('preview-bytes')
        ->assertHeader('content-type', 'image/jpeg');
});

test('signed preview route streams supported local asset categories', function (string $path, string $mimeType, string $bytes) {
    Storage::fake('local');
    Storage::disk('local')->put($path, $bytes);
    $workspace = Workspace::factory()->create();
    $media = previewAsset($workspace, [
        'path' => $path,
        'mime_type' => $mimeType,
        'original_filename' => basename($path),
        'size' => strlen($bytes),
    ]);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($media, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($preview['url'])
        ->assertOk()
        ->assertStreamed()
        ->assertStreamedContent($bytes)
        ->assertHeader('content-type', $mimeType);
})->with([
    'image' => ['media/preview-image.jpg', 'image/jpeg', 'image-bytes'],
    'video' => ['media/preview-video.mp4', 'video/mp4', 'video-bytes'],
    'pdf' => ['media/preview-document.pdf', 'application/pdf', '%PDF-bytes'],
]);

test('signed preview route supports unicode filenames in content disposition', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/preview-video.mp4', 'video-bytes');
    $workspace = Workspace::factory()->create();
    $media = previewAsset($workspace, [
        'path' => 'media/preview-video.mp4',
        'mime_type' => 'video/mp4',
        'original_filename' => 'città onboard 100%.mp4',
        'size' => strlen('video-bytes'),
    ]);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($media, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($preview['url'])
        ->assertOk()
        ->assertStreamed()
        ->assertHeader('content-disposition', "inline; filename=\"citta onboard 100-.mp4\"; filename*=utf-8''citt%C3%A0%20onboard%20100%25.mp4");
});

test('signed preview route rejects tampered signatures and cross workspace assets', function () {
    Storage::fake('local');
    Storage::disk('local')->put('media/preview.jpg', 'preview-bytes');
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $media = previewAsset($workspace);
    $foreignMedia = previewAsset($otherWorkspace);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($media, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($preview['url'].'tampered=1')->assertForbidden();

    $foreignPreview = (new AssetPreviewUrlFactory)->temporaryUrl($foreignMedia, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($foreignPreview['url'])->assertNotFound();
});

test('signed preview route returns not found when storage object is missing', function () {
    Storage::fake('local');
    $workspace = Workspace::factory()->create();
    $media = previewAsset($workspace);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($media, $workspace, CarbonImmutable::now('UTC')->addMinutes(5));

    $this->get($preview['url'])->assertNotFound();
});
