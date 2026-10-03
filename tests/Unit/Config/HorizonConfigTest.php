<?php

declare(strict_types=1);

use App\Dto\RemoteFile;
use App\Jobs\Media\AdoptWorkspaceLibraryJob;
use App\Jobs\Media\ImportRemoteMedia;
use App\Jobs\RssFeed\FetchRssFeed;
use App\Jobs\RssFeed\FetchRssFeedItemImage;

test('no horizon supervisor works the ai queue', function () {
    $queues = collect(config('horizon.defaults'))
        ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
        ->all();

    expect($queues)->not->toContain('ai')
        ->and(config('horizon.defaults'))->not->toHaveKey('ai-assistant');
});

test('rss feeds are worked only by their own supervisor', function () {
    $others = collect(config('horizon.defaults'))
        ->except('rss-feeds')
        ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
        ->all();

    expect(config('horizon.defaults.rss-feeds.queue'))->toBe(['rss-feeds'])
        ->and($others)->not->toContain('rss-feeds')
        ->and(config('horizon.environments.production.rss-feeds'))->toBeArray()
        ->and(config('horizon.environments.local.rss-feeds'))->toBeArray();
});

test('the rss-feeds supervisor outlives the jobs it runs', function () {
    $timeout = config('horizon.defaults.rss-feeds.timeout');

    expect($timeout)->toBeGreaterThan((new ReflectionClass(FetchRssFeed::class))->getProperty('timeout')->getDefaultValue())
        ->and($timeout)->toBeGreaterThan((new ReflectionClass(FetchRssFeedItemImage::class))->getProperty('timeout')->getDefaultValue());
});

test('media imports are worked only by their own supervisor', function () {
    $others = collect(config('horizon.defaults'))
        ->except('media-imports')
        ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
        ->all();

    expect(config('horizon.defaults.media-imports.queue'))->toBe([ImportRemoteMedia::QUEUE])
        ->and($others)->not->toContain(ImportRemoteMedia::QUEUE)
        ->and(config('horizon.environments.production.media-imports'))->toBeArray()
        ->and(config('horizon.environments.local.media-imports'))->toBeArray();
});

test('the media-imports supervisor outlives the import and redis never reserves it twice', function () {
    config()->set('queue.default', 'redis');
    $job = new ImportRemoteMedia('import', 'workspace', 'user', new RemoteFile('https://example.com/a.png', 'a.png'));

    expect($job->queue)->toBe(ImportRemoteMedia::QUEUE)
        ->and($job->timeout)->toBe((int) config('trypost.media_sources.import_timeout_seconds'))
        ->and($job->timeout)->toBeLessThan(config('horizon.defaults.media-imports.timeout'))
        ->and(config('horizon.defaults.media-imports.timeout'))->toBeLessThan(config('queue.connections.redis.retry_after'));
});

test('the import timeout stays below a short retry_after so the database queue never reserves it twice', function () {
    config()->set(['queue.default' => 'database', 'queue.connections.database.retry_after' => 90]);

    $job = new ImportRemoteMedia('import', 'workspace', 'user', new RemoteFile('https://example.com/a.png', 'a.png'));

    expect($job->timeout)->toBe(60)
        ->and($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

test('the library adoption is worked only by its own supervisor, which outlives the job', function () {
    $others = collect(config('horizon.defaults'))
        ->except('media-adoption')
        ->flatMap(fn (array $supervisor): array => (array) data_get($supervisor, 'queue', []))
        ->all();

    expect(config('horizon.defaults.media-adoption.queue'))->toBe([AdoptWorkspaceLibraryJob::QUEUE])
        ->and($others)->not->toContain(AdoptWorkspaceLibraryJob::QUEUE)
        ->and(config('horizon.defaults.media-adoption.timeout'))->toBeGreaterThan((new ReflectionClass(AdoptWorkspaceLibraryJob::class))->getProperty('timeout')->getDefaultValue())
        ->and(config('horizon.environments.production.media-adoption.maxProcesses'))->toBe(20)
        ->and(config('horizon.environments.local.media-adoption'))->toBeArray();
});
