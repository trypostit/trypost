<?php

declare(strict_types=1);

use App\Services\Media\ChunkedCloudUploader;

beforeEach(function () {
    $this->chunksDir = storage_path('app/private/chunks');

    if (! is_dir($this->chunksDir)) {
        mkdir($this->chunksDir, 0755, true);
    }
});

afterEach(function () {
    foreach (glob("{$this->chunksDir}/*") ?: [] as $file) {
        @unlink($file);
    }
});

test('command deletes chunk files older than the cache TTL', function () {
    $old = "{$this->chunksDir}/old-attempt";
    file_put_contents($old, 'partial-bytes');
    touch($old, now()->subHours(ChunkedCloudUploader::CACHE_TTL_HOURS + 1)->getTimestamp());

    $this->artisan('chunks:prune')->assertExitCode(0);

    expect(file_exists($old))->toBeFalse();
});

test('command leaves recent chunk files alone', function () {
    $recent = "{$this->chunksDir}/recent-attempt";
    file_put_contents($recent, 'partial-bytes');
    touch($recent, now()->subMinutes(10)->getTimestamp());

    $this->artisan('chunks:prune')->assertExitCode(0);

    expect(file_exists($recent))->toBeTrue();
});

test('command is a no-op when the chunks directory does not exist', function () {
    foreach (glob("{$this->chunksDir}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($this->chunksDir);

    $this->artisan('chunks:prune')->assertExitCode(0);

    expect(is_dir($this->chunksDir))->toBeFalse();
});
