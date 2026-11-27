<?php

declare(strict_types=1);

use App\Console\Commands\Admin\SyncPlatformQuotas;
use App\Enums\Admin\QuotaSource;
use App\Services\Admin\Quotas\YouTubeQuotaReader;
use App\Support\Admin\QuotaSnapshots;
use Google\Client as GoogleClient;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('trypost.admin.quotas.youtube', [
        'enabled' => true,
        'project_id' => 'trypost-test',
        'credentials' => base64_encode(json_encode(['client_id' => 'quota-reader.apps.googleusercontent.com', 'client_secret' => 'secret'])),
        'timezone' => 'America/Los_Angeles',
    ]);
});

/**
 * Answers Google Cloud Monitoring from memory with the given `timeSeries`
 * bodies, one per request, and records each request in `$sent`.
 *
 * @param  list<array<string, mixed>>  $bodies
 * @param  array<int, array<string, mixed>>  $sent
 */
function adminFakeGoogleMonitoring(array $bodies, array &$sent = []): GoogleClient
{
    $stack = HandlerStack::create(new MockHandler(collect($bodies)->map(fn (array $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body)))->all()));
    $stack->push(Middleware::history($sent));

    $client = new GoogleClient;
    $client->setHttpClient(new GuzzleClient(['handler' => $stack]));
    app()->instance(GoogleClient::class, $client);

    return $client;
}

test('reads each series\' latest point, whole or decimal, and an empty point as zero', function () {
    $sent = [];
    $client = adminFakeGoogleMonitoring([['timeSeries' => [
        ['metric' => ['labels' => ['quota_metric' => 'youtube.googleapis.com/default']], 'resource' => ['labels' => ['service' => 'youtube.googleapis.com']], 'points' => [['value' => ['int64Value' => '3200']]]],
        ['metric' => ['labels' => ['quota_metric' => 'youtube.googleapis.com/uploads']], 'points' => [['value' => ['doubleValue' => 12.6]]]],
        ['metric' => ['labels' => ['quota_metric' => 'youtube.googleapis.com/search']], 'points' => []],
    ]]], $sent);

    $series = app(YouTubeQuotaReader::class)->fetchTimeSeries(['filter' => 'metric.type="x"']);

    expect($series)->toBe([
        ['labels' => ['service' => 'youtube.googleapis.com', 'quota_metric' => 'youtube.googleapis.com/default'], 'value' => 3200],
        ['labels' => ['quota_metric' => 'youtube.googleapis.com/uploads'], 'value' => 13],
        ['labels' => ['quota_metric' => 'youtube.googleapis.com/search'], 'value' => 0],
    ])
        ->and($client->getClientId())->toBe('quota-reader.apps.googleusercontent.com')
        ->and((string) $sent[0]['request']->getUri())->toContain('projects/trypost-test/timeSeries');
});

test('reads no series when Google reports none', function () {
    adminFakeGoogleMonitoring([[]]);

    expect(app(YouTubeQuotaReader::class)->fetchTimeSeries(['filter' => 'metric.type="x"']))->toBe([]);
});

test('keeps the YouTube reading when X fails', function () {
    config()->set('trypost.admin.quotas.x', ['enabled' => true, 'bearer_token' => 'x-bearer']);
    Http::fake(['*' => Http::response(['title' => 'Service Unavailable'], 503)]);
    adminFakeGoogleMonitoring([
        ['timeSeries' => [['metric' => ['labels' => ['quota_metric' => 'youtube.googleapis.com/default']], 'points' => [['value' => ['int64Value' => '3200']]]]]],
        ['timeSeries' => [['metric' => ['labels' => ['quota_metric' => 'youtube.googleapis.com/default', 'limit_name' => 'defaultPerDayPerProject']], 'points' => [['value' => ['int64Value' => '10000']]]]]],
    ]);

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    expect(data_get(QuotaSnapshots::latest(QuotaSource::YouTube), 'data.quotas.0'))->toBe([
        'metric' => 'youtube.googleapis.com/default',
        'usage' => 3200,
        'limits' => [['name' => 'defaultPerDayPerProject', 'value' => 10000]],
    ])
        ->and(QuotaSnapshots::latest(QuotaSource::X))->toBeNull();
});
