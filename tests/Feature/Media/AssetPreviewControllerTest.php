<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake();

    $this->workspace = Workspace::factory()->create();
    $this->asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'original_filename' => 'hero.jpg',
        'mime_type' => 'image/jpeg',
    ]);
    Storage::put($this->asset->path, 'preview-bytes');
});

test('serves a signed local asset preview', function () {
    $url = URL::temporarySignedRoute('media.asset-preview.show', now()->addMinutes(5), [
        'workspace' => $this->workspace->id,
        'media' => $this->asset->id,
    ]);

    $response = $this->get($url);

    $response->assertOk()->assertHeader('content-type', 'image/jpeg');

    expect($response->baseResponse->getFile()->getContent())->toBe('preview-bytes');
});

test('rejects an unsigned preview url', function () {
    $this->get(route('media.asset-preview.show', [
        'workspace' => $this->workspace->id,
        'media' => $this->asset->id,
    ]))->assertForbidden();
});

test('returns not found for a missing file or foreign workspace', function () {
    $other = Workspace::factory()->create();

    $missingFile = URL::temporarySignedRoute('media.asset-preview.show', now()->addMinutes(5), [
        'workspace' => $this->workspace->id,
        'media' => $this->asset->id,
    ]);
    Storage::delete($this->asset->path);

    $this->get($missingFile)->assertNotFound();

    Storage::put($this->asset->path, 'preview-bytes');

    $foreign = URL::temporarySignedRoute('media.asset-preview.show', now()->addMinutes(5), [
        'workspace' => $other->id,
        'media' => $this->asset->id,
    ]);

    $this->get($foreign)->assertNotFound();
});
