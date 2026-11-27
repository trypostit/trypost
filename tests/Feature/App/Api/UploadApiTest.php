<?php

declare(strict_types=1);

use App\Models\Media;
use App\Support\HeicConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->headers = ['Authorization' => 'Bearer '.$result['plain_token'], 'Accept' => 'application/json'];
});

afterEach(fn () => HeicConverter::flush());

test('an upload returns a temporary token that expires after the retention', function () {
    $response = $this->withHeaders($this->headers)
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->image('shot.png', 40, 40)])
        ->assertCreated()
        ->assertJsonStructure(['upload_token', 'type', 'mime_type', 'size', 'original_filename', 'url', 'expires_at']);

    $media = Media::query()->sole();

    expect(Str::isUuid($response->json('upload_token')))->toBeTrue()
        ->and($response->json('upload_token'))->toBe($media->upload_token)
        ->and($response->json('type'))->toBe('image')
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($response->json('expires_at'))->toBe($media->created_at->copy()->addHours((int) config('trypost.media.upload_retention_hours'))->toIso8601String());
    Storage::assertExists($media->path);
});

test('an upload needs an api key', function () {
    $this->postJson(route('api.uploads.create'), [])->assertUnauthorized();
});

test('an unsupported file is refused', function () {
    $this->withHeaders($this->headers)
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->createWithContent('run.exe', 'MZ binary')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Media::query()->count())->toBe(0);
});

test('a heic upload is refused with the unavailable message when conversion is off', function () {
    config(['trypost.media.heic_conversion' => false]);
    HeicConverter::flush();

    $this->withHeaders($this->headers)
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->createWithContent('photo.heic', file_get_contents(base_path('tests/fixtures/sample.heic')))])
        ->assertUnprocessable()
        ->assertJsonPath('errors.media.0', __('posts.composer.upload_errors.heic_unavailable'));
});

test('an upload over the image cap is refused', function () {
    config(['trypost.media.max_size_mb.image' => 1]);

    $this->withHeaders($this->headers)
        ->post(route('api.uploads.create'), ['media' => UploadedFile::fake()->image('big.jpg')->size(2048)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);
});
