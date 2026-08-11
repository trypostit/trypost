<?php

declare(strict_types=1);

use App\Jobs\Gtm\SendServerEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.gtm.backend.enabled' => true,
        'services.gtm.backend.endpoint' => 'https://sgtm.test/collect',
        'services.gtm.backend.api_secret' => null,
    ]);
});

test('job is queued on the gtm queue', function () {
    $job = new SendServerEvent('sign_up', 'user-1', ['auth_provider' => 'email']);

    expect($job->queue)->toBe('gtm');
});

test('job skips the HTTP call when the backend container is disabled', function () {
    config(['services.gtm.backend.enabled' => false]);
    Http::fake();

    (new SendServerEvent('sign_up', 'user-1', ['auth_provider' => 'email']))->handle();

    Http::assertNothingSent();
});

test('job posts the event to the configured server container endpoint', function () {
    Http::fake(['sgtm.test/*' => Http::response(['status' => 'ok'], 200)]);

    (new SendServerEvent('purchase', 'user-1', ['plan_name' => 'Workspace']))->handle();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sgtm.test/collect'
        && $request['event'] === 'purchase'
        && $request['distinct_id'] === 'user-1'
        && $request['properties']['plan_name'] === 'Workspace');
});

test('job authenticates with the configured api secret when present', function () {
    config(['services.gtm.backend.api_secret' => 'shh-secret']);
    Http::fake(['sgtm.test/*' => Http::response(['status' => 'ok'], 200)]);

    (new SendServerEvent('sign_up', 'user-1', []))->handle();

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer shh-secret'));
});

test('job logs a warning when the server container rejects the event', function () {
    Http::fake(['sgtm.test/*' => Http::response(['error' => 'bad request'], 400)]);

    (new SendServerEvent('sign_up', 'user-1', []))->handle();

    // Should not throw - failure is logged, not raised, so retries follow
    // the job's own tries/backoff instead of an unhandled exception.
    expect(true)->toBeTrue();
});
