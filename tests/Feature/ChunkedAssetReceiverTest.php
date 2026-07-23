<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\ChunkedAssetReceiver;
use App\Services\Media\ChunkedCloudUploader;
use App\Services\Media\ChunkReceipt;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('chunk receipt in-progress response only exposes progress', function () {
    $response = ChunkReceipt::inProgress(42)->toResponse();

    expect($response->getData(true))->toBe([
        'done' => false,
        'progress' => 42,
    ]);
});

test('chunk receipt completed response merges media resource fields', function () {
    $media = Media::factory()->create([
        'mediable_type' => $this->workspace->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'assets',
        'type' => 'video',
        'path' => 'medias/clip.mp4',
        'original_filename' => 'clip.mp4',
        'mime_type' => 'video/mp4',
        'size' => 12,
    ]);

    $payload = ChunkReceipt::completed($media)->toResponse()->getData(true);

    expect($payload['done'])->toBeTrue();
    expect($payload['id'])->toBe($media->id);
    expect($payload['path'])->toBe('medias/clip.mp4');
    expect($payload['type'])->toBe('video');
    expect($payload['original_filename'])->toBe('clip.mp4');
    expect($payload)->toHaveKeys(['url', 'mime_type', 'size', 'meta', 'created_at']);
});

test('receiver assembles locally when multipart is not used', function () {
    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->with('clip.mp4')->andReturn(false);
    $cloud->shouldNotReceive('receiveChunk');

    $receiver = new ChunkedAssetReceiver($cloud);
    $bytes = "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat("\0", 64);

    $receipt = $receiver->receive(
        $this->workspace,
        $this->user,
        'clip.mp4',
        $bytes,
        0,
        strlen($bytes) - 1,
        strlen($bytes),
    );

    expect($receipt->done)->toBeTrue();
    expect($receipt->media)->toBeInstanceOf(Media::class);
    expect($receipt->media->type->value)->toBe('video');
    Storage::assertExists($receipt->media->path);
});

test('receiver reports progress for intermediate local chunks', function () {
    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->andReturn(false);

    $receiver = new ChunkedAssetReceiver($cloud);
    $part = str_repeat('a', 100);
    $total = 250;

    $receipt = $receiver->receive(
        $this->workspace,
        $this->user,
        'clip.mp4',
        $part,
        0,
        99,
        $total,
    );

    expect($receipt->done)->toBeFalse();
    expect($receipt->progress)->toBe(40);
    expect($this->workspace->getMedia('assets')->count())->toBe(0);
});

test('receiver completes multipart uploads through the cloud uploader', function () {
    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->with('clip.mp4')->andReturn(true);
    $cloud->shouldReceive('receiveChunk')
        ->once()
        ->andReturn([
            'done' => true,
            'progress' => 100,
            'path' => 'medias/from-cloud.mp4',
            'size' => 12,
            'mime_type' => 'video/mp4',
        ]);

    $receiver = new ChunkedAssetReceiver($cloud);

    $receipt = $receiver->receive(
        $this->workspace,
        $this->user,
        'clip.mp4',
        'fake-video!!',
        0,
        11,
        12,
    );

    expect($receipt->done)->toBeTrue();
    expect($receipt->media->path)->toBe('medias/from-cloud.mp4');
    expect($receipt->media->size)->toBe(12);
});

test('receiver returns in-progress when multipart chunk is not final', function () {
    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->andReturn(true);
    $cloud->shouldReceive('receiveChunk')
        ->once()
        ->andReturn(['done' => false, 'progress' => 55]);

    $receiver = new ChunkedAssetReceiver($cloud);

    $receipt = $receiver->receive(
        $this->workspace,
        $this->user,
        'clip.mp4',
        str_repeat('a', 100),
        0,
        99,
        200,
    );

    expect($receipt->done)->toBeFalse();
    expect($receipt->progress)->toBe(55);
    expect($this->workspace->getMedia('assets')->count())->toBe(0);
});

test('receiver deletes the cloud object when media registration fails after multipart', function () {
    Storage::put('medias/orphan.mp4', 'uploaded-bytes');

    $cloud = Mockery::mock(ChunkedCloudUploader::class);
    $cloud->shouldReceive('shouldUseMultipart')->andReturn(true);
    $cloud->shouldReceive('receiveChunk')
        ->once()
        ->andReturn([
            'done' => true,
            'progress' => 100,
            'path' => 'medias/orphan.mp4',
            'size' => 14,
            'mime_type' => 'application/zip',
        ]);

    $receiver = new ChunkedAssetReceiver($cloud);

    expect(fn () => $receiver->receive(
        $this->workspace,
        $this->user,
        'clip.mp4',
        'uploaded-bytes',
        0,
        13,
        14,
    ))->toThrow(InvalidArgumentException::class);

    Storage::assertMissing('medias/orphan.mp4');
    expect($this->workspace->getMedia('assets')->count())->toBe(0);
});
