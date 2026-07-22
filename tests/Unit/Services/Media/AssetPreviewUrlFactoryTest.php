<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\AssetPreviewUrlFactory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

test('local storage uses a temporary signed route without exposing storage paths', function () {
    Config::set('filesystems.default', 'local');
    $workspace = Workspace::factory()->create();
    $media = Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
        'collection' => 'assets',
        'path' => 'media/private-preview.jpg',
    ]);

    $preview = (new AssetPreviewUrlFactory)->temporaryUrl(
        $media,
        $workspace,
        CarbonImmutable::parse('2026-07-22 12:05:00', 'UTC'),
    );

    expect($preview['mode'])->toBe('signed_route')
        ->and($preview['expires_at'])->toBe('2026-07-22T12:05:00+00:00')
        ->and($preview['url'])->not->toContain('media/private-preview.jpg')
        ->and(URL::hasValidSignature(request()->create($preview['url'], 'GET')))->toBeTrue();
});
