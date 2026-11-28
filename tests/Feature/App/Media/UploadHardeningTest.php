<?php

declare(strict_types=1);

use App\Actions\Media\PruneTemporaryUploads;
use App\Enums\Media\Type as MediaType;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\ChunkedAssetReceiver;
use App\Services\Media\ChunkedCloudUploader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

const UPLOAD_HARDENING_SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.cookie)</script></svg>';

beforeEach(function () {
    Storage::fake();
    Cache::flush();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->account);
});

function uploadHardeningChunk(User $user, string $content, string $fileName, string $uploadId, int $rangeStart = 0, ?int $totalSize = null, ?int $rangeEnd = null): TestResponse
{
    $totalSize ??= strlen($content);
    $rangeEnd ??= $rangeStart + strlen($content) - 1;

    return test()->actingAs($user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => "bytes {$rangeStart}-{$rangeEnd}/{$totalSize}",
            'HTTP_X_FILE_NAME' => $fileName,
            'HTTP_X_UPLOAD_ID' => $uploadId,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );
}

function uploadHardeningRealFile(string $content, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'hardening_');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, null, null, true);
}

function uploadHardeningHugePng(int $width, int $height): string
{
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n"
        .$chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
        .$chunk('IDAT', (string) gzcompress(''))
        .$chunk('IEND', '');
}

function uploadHardeningChunkPath(User $user, string $fileName, int $totalSize, string $uploadId): string
{
    return ChunkedAssetReceiver::chunkDirectory().'/'.md5("{$user->id}{$fileName}{$totalSize}{$uploadId}");
}

test('a chunked file whose bytes are outside the allow-list is rejected whatever its name says', function (string $content, string $fileName) {
    $uploadId = Str::uuid()->toString();

    uploadHardeningChunk($this->user, $content, $fileName, $uploadId)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_name' => __('posts.composer.upload_errors.unsupported_type')]);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([])
        ->and(File::exists(uploadHardeningChunkPath($this->user, $fileName, strlen($content), $uploadId)))->toBeFalse();
})->with([
    'svg with a script named jpg' => [UPLOAD_HARDENING_SVG, 'avatar.jpg'],
    'webm named mp4' => [fn () => file_get_contents(base_path('tests/fixtures/cover-3s.webm')), 'clip.mp4'],
    'webm named mov' => [fn () => file_get_contents(base_path('tests/fixtures/cover-3s.webm')), 'clip.mov'],
    'text named pdf' => ['just some plain text, not a document', 'deck.pdf'],
]);

test('a multipart chunked upload whose detected type is outside the allow-list is rejected and its object deleted', function () {
    config(['filesystems.default' => 's3', 'filesystems.disks.s3.driver' => 's3']);
    Storage::fake('s3');
    Storage::put('medias/clip.mp4', 'webm-bytes');

    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->andReturn(true);
    $cloud->shouldReceive('receiveChunk')->once()->andReturn([
        'done' => true, 'progress' => 100, 'path' => 'medias/clip.mp4', 'size' => 10, 'mime_type' => 'video/webm',
    ]);
    app()->instance(ChunkedCloudUploader::class, $cloud);

    uploadHardeningChunk($this->user, 'webm-bytes', 'clip.mp4', Str::uuid()->toString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file_name' => __('posts.composer.upload_errors.unsupported_type')]);

    expect(Media::query()->count())->toBe(0);
    Storage::assertMissing('medias/clip.mp4');
});

test('an svg with a script named jpg is refused by the api and the signed upload', function () {
    $headers = ['Authorization' => 'Bearer '.createApiTestToken()['plain_token'], 'Accept' => 'application/json'];

    $this->withHeaders($headers)
        ->post(route('api.uploads.create'), ['media' => uploadHardeningRealFile(UPLOAD_HARDENING_SVG, 'avatar.jpg')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    $signedUrl = URL::temporarySignedRoute('api.uploads.store', now()->addMinutes(5), [
        'token' => Str::uuid()->toString(),
        'workspace_id' => $this->workspace->id,
    ]);

    $this->post($signedUrl, ['media' => uploadHardeningRealFile(UPLOAD_HARDENING_SVG, 'avatar.jpg')], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Media::query()->count())->toBe(0);
});

test('storing media refuses a file outside the allow-list whichever entry point calls it', function () {
    $path = tempnam(sys_get_temp_dir(), 'hardening_');
    file_put_contents($path, UPLOAD_HARDENING_SVG);

    try {
        expect(fn () => $this->workspace->addMediaFromPath($path, 'avatar.jpg', Media::COLLECTION_UPLOADS))
            ->toThrow(ValidationException::class);
        expect(fn () => $this->workspace->addMediaFromPath($path, 'avatar.jpg', Media::COLLECTION_UPLOADS, mimeType: 'image/svg+xml'))
            ->toThrow(ValidationException::class);
        expect(fn () => $this->workspace->addMediaFromStoredPath('medias/x.svg', 'x.svg', 'image/svg+xml', 10, Media::COLLECTION_UPLOADS))
            ->toThrow(ValidationException::class);
    } finally {
        @unlink($path);
    }

    expect(Media::query()->count())->toBe(0);
});

test('an image whose header declares too many pixels is rejected before it is decoded', function () {
    uploadHardeningChunk($this->user, uploadHardeningHugePng(60000, 60000), 'bomb.png', Str::uuid()->toString())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media' => __('posts.composer.upload_errors.image_too_large')]);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

test('an image within the pixel limit is still stored', function () {
    uploadHardeningChunk($this->user, file_get_contents(base_path('tests/fixtures/1x1.png')), 'photo.png', Str::uuid()->toString())
        ->assertOk()
        ->assertJson(['done' => true, 'type' => MediaType::Image->value]);
});

test('a chunk whose length does not match its content-range is rejected', function () {
    $uploadId = Str::uuid()->toString();

    uploadHardeningChunk($this->user, str_repeat('a', 500), 'clip.mp4', $uploadId, rangeStart: 0, totalSize: 1000, rangeEnd: 9)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range_start']);

    expect(File::exists(uploadHardeningChunkPath($this->user, 'clip.mp4', 1000, $uploadId)))->toBeFalse();
});

test('a chunk at an unexpected offset is rejected and a replayed chunk is not appended twice', function () {
    $uploadId = Str::uuid()->toString();

    uploadHardeningChunk($this->user, str_repeat('a', 100), 'clip.mp4', $uploadId, rangeStart: 0, totalSize: 300)
        ->assertOk()->assertJson(['done' => false]);
    uploadHardeningChunk($this->user, str_repeat('a', 100), 'clip.mp4', $uploadId, rangeStart: 0, totalSize: 300)
        ->assertOk()->assertJson(['done' => false]);
    uploadHardeningChunk($this->user, str_repeat('b', 100), 'clip.mp4', $uploadId, rangeStart: 200, totalSize: 300)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range_start']);

    $chunkPath = uploadHardeningChunkPath($this->user, 'clip.mp4', 300, $uploadId);

    expect(File::size($chunkPath))->toBe(100);

    File::delete($chunkPath);
});

test('a chunk that would grow the file past the declared total is rejected', function () {
    $uploadId = Str::uuid()->toString();

    uploadHardeningChunk($this->user, str_repeat('a', 100), 'clip.mp4', $uploadId, rangeStart: 0, totalSize: 150)
        ->assertOk();
    uploadHardeningChunk($this->user, str_repeat('a', 100), 'clip.mp4', $uploadId, rangeStart: 100, totalSize: 150, rangeEnd: 199)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range_start']);

    File::delete(uploadHardeningChunkPath($this->user, 'clip.mp4', 150, $uploadId));
});

test('abandoned chunk files past the upload retention are pruned and fresh ones are kept', function () {
    File::ensureDirectoryExists(ChunkedAssetReceiver::chunkDirectory());
    $stale = ChunkedAssetReceiver::chunkDirectory().'/'.Str::uuid();
    $fresh = ChunkedAssetReceiver::chunkDirectory().'/'.Str::uuid();
    File::put($stale, 'old');
    File::put($fresh, 'new');
    $future = now()->addYear();
    touch($fresh, $future->getTimestamp());
    touch($stale, $future->copy()->subHours((int) config('trypost.media.upload_retention_hours') + 1)->getTimestamp());

    try {
        expect(data_get(PruneTemporaryUploads::execute($future, dryRun: true), 'chunks'))->toBeGreaterThanOrEqual(1)
            ->and(File::exists($stale))->toBeTrue();

        PruneTemporaryUploads::execute($future);

        expect(File::exists($stale))->toBeFalse()
            ->and(File::exists($fresh))->toBeTrue();
    } finally {
        File::delete([$stale, $fresh]);
    }
});

test('the media file endpoint serves allowed images with headers that keep them passive', function () {
    $media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => 'medias/photo.png', 'mime_type' => 'image/png']);
    Storage::put('medias/photo.png', 'png-bytes');

    $this->actingAs($this->user)->get(route('app.media.file', $media))
        ->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
});

test('the media file endpoint never serves an svg, a video or a pdf', function (string $path, string $mimeType, string $type) {
    $media = Media::factory()->temporaryUpload($this->workspace)->create(['path' => $path, 'mime_type' => $mimeType, 'type' => $type]);
    Storage::put($path, UPLOAD_HARDENING_SVG);

    $this->actingAs($this->user)->get(route('app.media.file', $media))->assertNotFound();
})->with([
    'stored svg' => ['medias/legacy.svg', 'image/svg+xml', 'image'],
    'video' => ['medias/clip.mp4', 'video/mp4', 'video'],
    'pdf' => ['medias/deck.pdf', 'application/pdf', 'document'],
]);
