<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// Public IP literals let SafeHttpFetcher's SSRF guard pass without a real DNS
// lookup; Http::fake intercepts the request before any network I/O.

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('attaches a media item downloaded from a public url', function () {
    Http::fake([
        'https://93.184.216.34/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $result = app(MediaAttacher::class)->attachFromUrls($this->post, [
        ['url' => 'https://93.184.216.34/photo.png'],
    ]);

    expect($result['failed'])->toBeEmpty()
        ->and($result['attached'])->toHaveCount(1);

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
});

test('blocks a private-network url and never requests it', function () {
    Http::fake();

    $result = app(MediaAttacher::class)->attachFromUrls($this->post, [
        ['url' => 'http://127.0.0.1/evil.jpg'],
    ]);

    expect($result['attached'])->toBeEmpty()
        ->and($result['failed'])->toBe(['http://127.0.0.1/evil.jpg']);

    Http::assertNothingSent();
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

test('attempts the internal fetch when allow_private_network is enabled', function () {
    config(['trypost.security.allow_private_network' => true]);

    Http::fake([
        'http://127.0.0.1/internal.png' => Http::response(
            file_get_contents(__DIR__.'/../../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $result = app(MediaAttacher::class)->attachFromUrls($this->post, [
        ['url' => 'http://127.0.0.1/internal.png'],
    ]);

    expect($result['failed'])->toBeEmpty()
        ->and($result['attached'])->toHaveCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});
