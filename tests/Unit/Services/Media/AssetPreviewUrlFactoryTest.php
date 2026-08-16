<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\AssetPreviewUrlFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->workspace = Workspace::factory()->create();
    $this->asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);
    Storage::put($this->asset->path, 'preview-bytes');
});

test('builds a signed route preview for local disks', function () {
    $expiresAt = CarbonImmutable::parse('2026-08-16 18:00:00', 'UTC');

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($this->asset, $this->workspace, $expiresAt);

    expect($preview['preview_mode'])->toBe('signed_route')
        ->and($preview['expires_at'])->toBe($expiresAt->toIso8601String())
        ->and($preview['preview_url'])->toContain('/media/asset-preview/')
        ->and($preview['preview_url'])->toContain('signature=');
});

test('builds a temporary url preview for s3 disks', function () {
    Config::set('filesystems.default', 's3');
    Config::set('filesystems.disks.s3.driver', 's3');

    Storage::shouldReceive('disk')->with('s3')->andReturnSelf();
    Storage::shouldReceive('temporaryUrl')
        ->once()
        ->andReturn('https://cdn.example.test/asset.jpg');

    $expiresAt = CarbonImmutable::parse('2026-08-16 18:00:00', 'UTC');
    $preview = (new AssetPreviewUrlFactory)->temporaryUrl($this->asset, $this->workspace, $expiresAt);

    expect($preview)->toMatchArray([
        'preview_url' => 'https://cdn.example.test/asset.jpg',
        'expires_at' => $expiresAt->toIso8601String(),
        'preview_mode' => 'temporary_url',
    ]);
});

test('ensure available rejects a missing object', function () {
    Storage::delete($this->asset->path);

    expect(fn () => (new AssetPreviewUrlFactory)->ensureAvailable($this->asset))
        ->toThrow(RuntimeException::class);
});
