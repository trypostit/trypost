<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\ChunkedCloudUploader;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Cache::flush();
});

function seedChunkedUploadWorkspace(): void
{
    test()->account = Account::factory()->create();
    test()->user = User::factory()->create(['account_id' => test()->account->id]);
    test()->account->update(['owner_id' => test()->user->id]);
    test()->workspace = Workspace::factory()->create([
        'account_id' => test()->account->id,
        'user_id' => test()->user->id,
    ]);
    test()->workspace->members()->attach(test()->user->id, ['role' => Role::Member->value]);
    test()->user->update(['current_workspace_id' => test()->workspace->id]);
    test()->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
}

function fakeMp4Bytes(): string
{
    return "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat("\0", 64);
}

function postChunkedAsset(string $fileName, string $content, int $rangeStart = 0, ?int $totalSize = null): TestResponse
{
    $totalSize ??= strlen($content);
    $rangeEnd = $rangeStart + strlen($content) - 1;

    return test()->actingAs(test()->user)->call(
        'POST',
        route('app.assets.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => "bytes {$rangeStart}-{$rangeEnd}/{$totalSize}",
            'HTTP_X_FILE_NAME' => rawurlencode($fileName),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );
}

// ─── Strategy selection (all disks × file types) ─────────────────

test('shouldUseMultipart is only true for object-storage disks with video or pdf', function (string $disk, string $driver, string $fileName, bool $expected) {
    config([
        "filesystems.disks.{$disk}.driver" => $driver,
        'filesystems.default' => $disk,
    ]);

    $uploader = new ChunkedCloudUploader(Cache::store(), disk: $disk);

    expect($uploader->shouldUseMultipart($fileName))->toBe($expected);
    expect($uploader->isObjectStorageDisk($disk))->toBe($driver === 's3');
})->with([
    'local video' => ['local', 'local', 'clip.mp4', false],
    'local pdf' => ['local', 'local', 'deck.pdf', false],
    'local image' => ['local', 'local', 'photo.png', false],
    'public video' => ['public', 'local', 'clip.mp4', false],
    'public pdf' => ['public', 'local', 'deck.pdf', false],
    'public image' => ['public', 'local', 'photo.png', false],
    's3 video' => ['s3', 's3', 'clip.mp4', true],
    's3 pdf' => ['s3', 's3', 'deck.pdf', true],
    's3 image' => ['s3', 's3', 'photo.png', false],
    'r2 video' => ['r2', 's3', 'clip.mp4', true],
    'r2 pdf' => ['r2', 's3', 'deck.pdf', true],
    'r2 image' => ['r2', 's3', 'photo.png', false],
    'spaces video' => ['spaces', 's3', 'clip.mp4', true],
    'spaces pdf' => ['spaces', 's3', 'deck.pdf', true],
    'spaces image' => ['spaces', 's3', 'photo.png', false],
]);

// ─── Multipart mechanics (object storage) ────────────────────────

test('chunked cloud uploader uploads parts and completes multipart', function () {
    $client = Mockery::mock(S3Client::class);

    $client->shouldReceive('createMultipartUpload')
        ->once()
        ->andReturn(new Result(['UploadId' => 'upload-1']));

    $client->shouldReceive('uploadPart')
        ->twice()
        ->andReturn(new Result(['ETag' => '"etag-a"']), new Result(['ETag' => '"etag-b"']));

    $client->shouldReceive('completeMultipartUpload')
        ->once()
        ->withArgs(function (array $args) {
            expect(data_get($args, 'UploadId'))->toBe('upload-1');
            expect(data_get($args, 'MultipartUpload.Parts'))->toHaveCount(2);

            return true;
        })
        ->andReturn(new Result([]));

    $uploader = new ChunkedCloudUploader(Cache::store(), $client, 'test-bucket', 'r2');
    $chunk1 = str_repeat('a', ChunkedCloudUploader::MIN_PART_BYTES);
    $chunk2 = str_repeat('b', 50);
    $total = strlen($chunk1) + strlen($chunk2);

    $mid = $uploader->receiveChunk('id-1', 'video.mp4', $chunk1, 0, strlen($chunk1) - 1, $total);
    expect($mid)->toMatchArray(['done' => false]);

    $done = $uploader->receiveChunk(
        'id-1',
        'video.mp4',
        $chunk2,
        strlen($chunk1),
        $total - 1,
        $total,
    );

    expect($done['done'])->toBeTrue();
    expect($done['size'])->toBe($total);
    expect($done['path'])->toStartWith('medias/');
    expect($done['path'])->toEndWith('.mp4');
    expect(Cache::get('chunked-cloud-upload:id-1'))->toBeNull();
});

test('chunked cloud uploader rejects undersized non-final parts', function () {
    $uploader = new ChunkedCloudUploader(
        Cache::store(),
        Mockery::mock(S3Client::class),
        'test-bucket',
        'r2',
    );

    expect(fn () => $uploader->receiveChunk(
        'id-small',
        'video.mp4',
        str_repeat('a', 100),
        0,
        99,
        ChunkedCloudUploader::MIN_PART_BYTES + 200,
    ))->toThrow(InvalidArgumentException::class);
});

test('chunked cloud uploader rejects unexpected offsets', function () {
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('createMultipartUpload')
        ->once()
        ->andReturn(new Result(['UploadId' => 'upload-1']));
    $client->shouldReceive('uploadPart')
        ->once()
        ->andReturn(new Result(['ETag' => '"etag-a"']));

    $uploader = new ChunkedCloudUploader(Cache::store(), $client, 'test-bucket', 'r2');
    $chunk1 = str_repeat('a', ChunkedCloudUploader::MIN_PART_BYTES);
    $total = ChunkedCloudUploader::MIN_PART_BYTES * 2;

    $uploader->receiveChunk('id-gap', 'video.mp4', $chunk1, 0, strlen($chunk1) - 1, $total);

    expect(fn () => $uploader->receiveChunk(
        'id-gap',
        'video.mp4',
        $chunk1,
        ChunkedCloudUploader::MIN_PART_BYTES + 10,
        ChunkedCloudUploader::MIN_PART_BYTES * 2 + 9,
        $total,
    ))->toThrow(InvalidArgumentException::class);
});

test('chunked cloud uploader is idempotent when a chunk is retried', function () {
    $client = Mockery::mock(S3Client::class);
    $client->shouldReceive('createMultipartUpload')
        ->once()
        ->andReturn(new Result(['UploadId' => 'upload-1']));
    $client->shouldReceive('uploadPart')
        ->once()
        ->andReturn(new Result(['ETag' => '"etag-a"']));

    $uploader = new ChunkedCloudUploader(Cache::store(), $client, 'test-bucket', 'r2');
    $chunk1 = str_repeat('a', ChunkedCloudUploader::MIN_PART_BYTES);
    $total = ChunkedCloudUploader::MIN_PART_BYTES + 50;

    $first = $uploader->receiveChunk('id-retry', 'video.mp4', $chunk1, 0, strlen($chunk1) - 1, $total);
    $retry = $uploader->receiveChunk('id-retry', 'video.mp4', $chunk1, 0, strlen($chunk1) - 1, $total);

    expect($first)->toMatchArray(['done' => false]);
    expect($retry)->toMatchArray(['done' => false, 'progress' => $first['progress']]);
});

// ─── HTTP: local / public assemble path ──────────────────────────

test('chunked upload stores video on the local disk via assemble path', function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    seedChunkedUploadWorkspace();

    $content = fakeMp4Bytes();
    $response = postChunkedAsset('clip.mp4', $content);

    $response->assertSuccessful();
    $response->assertJson(['done' => true, 'type' => 'video']);

    $media = test()->workspace->getMedia('assets')->first();
    expect($media->original_filename)->toBe('clip.mp4');
    expect($media->size)->toBe(strlen($content));
    Storage::disk('local')->assertExists($media->path);
});

test('chunked upload stores video on the public disk via assemble path', function () {
    config(['filesystems.default' => 'public']);
    Storage::fake('public');
    seedChunkedUploadWorkspace();

    $content = fakeMp4Bytes();
    $response = postChunkedAsset('clip.mp4', $content);

    $response->assertSuccessful();
    $response->assertJson(['done' => true, 'type' => 'video']);

    $media = test()->workspace->getMedia('assets')->first();
    expect($media->type->value)->toBe('video');
    Storage::disk('public')->assertExists($media->path);
});

test('chunked upload on local disk reports progress across multiple chunks', function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    seedChunkedUploadWorkspace();

    $part1 = fakeMp4Bytes();
    $part2 = str_repeat("\0", 50);
    $total = strlen($part1) + strlen($part2);

    $mid = postChunkedAsset('clip.mp4', $part1, 0, $total);
    $mid->assertSuccessful();
    $mid->assertJson(['done' => false]);
    expect(test()->workspace->getMedia('assets')->count())->toBe(0);

    $done = postChunkedAsset('clip.mp4', $part2, strlen($part1), $total);
    $done->assertSuccessful();
    $done->assertJson(['done' => true, 'type' => 'video']);
    expect(test()->workspace->getMedia('assets')->count())->toBe(1);
});

test('chunked upload stores image on local disk', function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    seedChunkedUploadWorkspace();

    $content = file_get_contents(__DIR__.'/../fixtures/1x1.png');
    $response = postChunkedAsset('photo.png', $content);

    $response->assertSuccessful();
    $response->assertJson(['done' => true, 'type' => 'image']);
    Storage::disk('local')->assertExists(test()->workspace->getMedia('assets')->first()->path);
});

// ─── HTTP: object storage ────────────────────────────────────────

test('chunked upload uses multipart for videos on s3 disks', function (string $disk) {
    config([
        'filesystems.default' => $disk,
        "filesystems.disks.{$disk}.driver" => 's3',
    ]);
    Storage::fake($disk);
    seedChunkedUploadWorkspace();

    $fake = Mockery::mock(ChunkedCloudUploader::class);
    $fake->shouldReceive('shouldUseMultipart')->with('clip.mp4')->andReturn(true);
    $fake->shouldReceive('receiveChunk')
        ->once()
        ->andReturn([
            'done' => true,
            'progress' => 100,
            'path' => "medias/{$disk}-clip.mp4",
            'size' => 12,
            'mime_type' => 'video/mp4',
        ]);
    app()->instance(ChunkedCloudUploader::class, $fake);

    $response = postChunkedAsset('clip.mp4', 'fake-video!!');

    $response->assertSuccessful();
    $response->assertJson([
        'done' => true,
        'path' => "medias/{$disk}-clip.mp4",
        'type' => 'video',
    ]);
    expect(test()->workspace->getMedia('assets')->first()->path)->toBe("medias/{$disk}-clip.mp4");
})->with(['s3', 'r2', 'spaces']);

test('chunked upload on s3 still assembles images without multipart', function () {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3.driver' => 's3',
    ]);
    Storage::fake('s3');
    seedChunkedUploadWorkspace();

    $mock = Mockery::mock(ChunkedCloudUploader::class);
    $mock->shouldReceive('shouldUseMultipart')->with('photo.png')->andReturn(false);
    $mock->shouldNotReceive('receiveChunk');
    app()->instance(ChunkedCloudUploader::class, $mock);

    $content = file_get_contents(__DIR__.'/../fixtures/1x1.png');
    $response = postChunkedAsset('photo.png', $content);

    $response->assertSuccessful();
    $response->assertJson(['done' => true, 'type' => 'image']);
    Storage::disk('s3')->assertExists(test()->workspace->getMedia('assets')->first()->path);
});

test('chunked upload on local never calls multipart receiveChunk for videos', function () {
    config(['filesystems.default' => 'local']);
    Storage::fake('local');
    seedChunkedUploadWorkspace();

    $mock = Mockery::mock(ChunkedCloudUploader::class);
    $mock->shouldReceive('shouldUseMultipart')->with('clip.mp4')->andReturn(false);
    $mock->shouldNotReceive('receiveChunk');
    app()->instance(ChunkedCloudUploader::class, $mock);

    $response = postChunkedAsset('clip.mp4', fakeMp4Bytes());

    $response->assertSuccessful();
    $response->assertJson(['done' => true, 'type' => 'video']);
    Storage::disk('local')->assertExists(test()->workspace->getMedia('assets')->first()->path);
});
