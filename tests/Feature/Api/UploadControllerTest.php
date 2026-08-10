<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
    Cache::flush();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
});

function signedUploadUrl(Workspace $ws, string $token, ?int $expiresInMinutes = 15): string
{
    return URL::temporarySignedRoute(
        'api.uploads.store',
        now()->addMinutes($expiresInMinutes),
        ['token' => $token, 'workspace_id' => $ws->id],
    );
}

test('valid signed POST stores Media with upload_token', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->image('shot.png', 50, 50);

    $response = $this->post(signedUploadUrl($this->workspace, $token), [
        'media' => $file,
    ]);

    $media = Media::where('upload_token', $token)->first();
    expect($media)->not->toBeNull();
    expect($media->mediable_id)->toBe($this->workspace->id);
    expect($media->mediable_type)->toBe('workspace');

    $response->assertCreated()
        ->assertJson([
            'upload_token' => $token,
            'media_id' => $media->id,
            'mime_type' => $media->mime_type,
            'original_filename' => $media->original_filename,
        ]);
});

test('sanitizes an invalid UTF-8 byte in the client filename instead of crashing the insert (Nightwatch #24)', function () {
    $token = (string) Str::uuid();
    // 0x97 is a raw Windows-1252 em dash, not valid UTF-8 on its own — Postgres
    // rejects it outright on insert unless the filename is sanitized first.
    $file = UploadedFile::fake()->image("earnings \x97 report.png", 50, 50);

    $response = $this->post(signedUploadUrl($this->workspace, $token), [
        'media' => $file,
    ]);

    $response->assertCreated();

    $media = Media::where('upload_token', $token)->first();
    expect($media)->not->toBeNull();
    expect(mb_check_encoding($media->original_filename, 'UTF-8'))->toBeTrue();
    expect($media->original_filename)->toBe('earnings ? report.png');
});

test('rejects unsigned request', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->image('shot.png', 50, 50);

    $response = $this->postJson(route('api.uploads.store', ['token' => $token, 'workspace_id' => $this->workspace->id]), [
        'media' => $file,
    ]);

    $response->assertForbidden();
    expect(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('rejects tampered workspace_id', function () {
    $other = Workspace::factory()->create();
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->image('shot.png', 50, 50);

    $url = signedUploadUrl($this->workspace, $token);
    $tampered = str_replace("workspace_id={$this->workspace->id}", "workspace_id={$other->id}", $url);

    $response = $this->postJson($tampered, ['media' => $file]);

    $response->assertForbidden();
});

test('rejects expired URL', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->image('shot.png', 50, 50);

    $url = URL::temporarySignedRoute(
        'api.uploads.store',
        now()->subMinute(),
        ['token' => $token, 'workspace_id' => $this->workspace->id],
    );

    $response = $this->postJson($url, ['media' => $file]);
    $response->assertForbidden();
});

test('rejects replay of an already-used token', function () {
    $token = (string) Str::uuid();
    $file1 = UploadedFile::fake()->image('one.png', 50, 50);
    $file2 = UploadedFile::fake()->image('two.png', 50, 50);

    $this->post(signedUploadUrl($this->workspace, $token), ['media' => $file1])->assertCreated();
    $this->postJson(signedUploadUrl($this->workspace, $token), ['media' => $file2])->assertStatus(409);

    expect(Media::where('upload_token', $token)->count())->toBe(1);
});

test('rejects file larger than the per-type media cap', function () {
    config(['trypost.media.max_size_mb.video' => 1]);

    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('huge.mp4', 1024 + 1, 'video/mp4');

    $response = $this->postJson(signedUploadUrl($this->workspace, $token), ['media' => $file]);

    $response->assertStatus(422);
    expect(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('rejects an image larger than the image media cap even when under the video ceiling', function () {
    config([
        'trypost.media.max_size_mb.image' => 1,
        'trypost.media.max_size_mb.video' => 1024,
    ]);

    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('big.jpg', 1024 + 1, 'image/jpeg');

    $response = $this->postJson(signedUploadUrl($this->workspace, $token), ['media' => $file]);

    $response->assertStatus(422);
    expect(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('rejects a document larger than the document media cap even when under the video ceiling', function () {
    config([
        'trypost.media.max_size_mb.document' => 1,
        'trypost.media.max_size_mb.video' => 1024,
    ]);

    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('big.pdf', 1024 + 1, 'application/pdf');

    $response = $this->postJson(signedUploadUrl($this->workspace, $token), ['media' => $file]);

    $response->assertStatus(422);
    expect(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('stores a video upload via the streaming path', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('clip.mp4', 256, 'video/mp4');

    $this->post(signedUploadUrl($this->workspace, $token), [
        'media' => $file,
    ])->assertCreated();

    $media = Media::where('upload_token', $token)->first();
    expect($media)->not->toBeNull()
        ->and($media->type->value)->toBe('video')
        ->and(Storage::exists($media->path))->toBeTrue();
});

test('releases the upload token when media persistence fails', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('clip.mp4', 256, 'video/mp4');
    $cacheKey = "media:signed-upload:{$token}";

    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new RuntimeException('disk unavailable'));

    $this->postJson(signedUploadUrl($this->workspace, $token), [
        'media' => $file,
    ])->assertServerError();

    expect(Cache::has($cacheKey))->toBeFalse()
        ->and(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('rejects disallowed mime type', function () {
    $token = (string) Str::uuid();
    $file = UploadedFile::fake()->create('evil.exe', 10, 'application/octet-stream');

    $response = $this->postJson(signedUploadUrl($this->workspace, $token), ['media' => $file]);

    $response->assertStatus(422);
    expect(Media::where('upload_token', $token)->exists())->toBeFalse();
});

test('rate limits floods from the same workspace', function () {
    for ($i = 0; $i < 60; $i++) {
        $this->postJson(
            signedUploadUrl($this->workspace, (string) Str::uuid()),
            ['media' => UploadedFile::fake()->image("f{$i}.png", 16, 16)],
        )->assertSuccessful();
    }

    $this->postJson(
        signedUploadUrl($this->workspace, (string) Str::uuid()),
        ['media' => UploadedFile::fake()->image('over.png', 16, 16)],
    )->assertStatus(429);
});

test('different workspaces on the same IP do not share the upload rate limit', function () {
    $otherWorkspace = Workspace::factory()->create();

    for ($i = 0; $i < 60; $i++) {
        $this->postJson(
            signedUploadUrl($this->workspace, (string) Str::uuid()),
            ['media' => UploadedFile::fake()->image("a{$i}.png", 16, 16)],
        )->assertSuccessful();
    }

    $this->postJson(
        signedUploadUrl($this->workspace, (string) Str::uuid()),
        ['media' => UploadedFile::fake()->image('blocked.png', 16, 16)],
    )->assertStatus(429);

    $this->postJson(
        signedUploadUrl($otherWorkspace, (string) Str::uuid()),
        ['media' => UploadedFile::fake()->image('other.png', 16, 16)],
    )->assertSuccessful();
});
