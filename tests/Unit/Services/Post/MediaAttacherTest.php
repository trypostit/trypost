<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
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

    expect($this->post->ownedMedia()->pluck('id')->all())->toBe([data_get($result, 'attached.0.id')])
        ->and(Media::query()->temporaryUploads()->count())->toBe(0);
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

test('downloads are requested uncompressed and a compressed body is rejected without leaving a temp file', function () {
    $body = gzencode(str_repeat('0', 4096));
    Http::fake(['https://93.184.216.34/photo.png' => Http::response($body, 200, ['Content-Type' => 'image/png', 'Content-Encoding' => 'gzip'])]);
    $before = glob(sys_get_temp_dir().'/media_*') ?: [];

    $result = app(MediaAttacher::class)->attachFromUrls($this->post, [['url' => 'https://93.184.216.34/photo.png']]);

    $leftovers = array_filter(
        array_diff(glob(sys_get_temp_dir().'/media_*') ?: [], $before),
        fn (string $path): bool => @file_get_contents($path) === $body,
    );

    Http::assertSent(fn ($request) => $request->hasHeader('Accept-Encoding', 'identity'));
    expect($result['attached'])->toBeEmpty()
        ->and($result['failed'])->toBe(['https://93.184.216.34/photo.png'])
        ->and($leftovers)->toBe([]);
});

test('a url that redirects is refused and the redirect target is never requested', function () {
    Http::fake([
        'https://93.184.216.34/photo.png' => Http::response('', 302, ['Location' => 'https://93.184.216.35/photo.png']),
        'https://93.184.216.35/*' => Http::response(file_get_contents(__DIR__.'/../../../fixtures/1x1.png')),
    ]);

    $result = app(MediaAttacher::class)->attachFromUrls($this->post, [['url' => 'https://93.184.216.34/photo.png']]);

    expect($result['attached'])->toBeEmpty()
        ->and($result['failed'])->toBe(['https://93.184.216.34/photo.png'])
        ->and(app(MediaAttacher::class)->hostUpload($this->workspace, [MediaType::Image], 'https://93.184.216.34/photo.png'))->toBeNull()
        ->and(Media::query()->count())->toBe(0);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '93.184.216.35'));
});

test('an rss image follows a redirect', function () {
    Http::fake([
        'https://93.184.216.34/photo.png' => Http::response('', 302, ['Location' => 'https://93.184.216.35/photo.png']),
        'https://93.184.216.35/*' => Http::response(file_get_contents(__DIR__.'/../../../fixtures/1x1.png')),
    ]);

    expect(app(MediaAttacher::class)->hostImage($this->workspace, 'https://93.184.216.34/photo.png'))->not->toBeNull();
});
