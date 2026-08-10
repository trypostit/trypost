<?php

declare(strict_types=1);

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\Meta\GraphPaginator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('graph paginator returns a single page when there is no paging.next', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                ['id' => 'page_only', 'name' => 'Only Page'],
            ],
        ], 200),
    ]);

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
        'limit' => 100,
    ]);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_only');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'limit=100')
        && str_contains($request->url(), 'access_token=token'));
});

test('graph paginator follows paging.next until exhausted', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $nextUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor1&limit=100";
    $thirdUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor2&limit=100";

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 'page_1', 'name' => 'First'],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    ['id' => 'page_2', 'name' => 'Second'],
                ],
                'paging' => [
                    'next' => $thirdUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    ['id' => 'page_3', 'name' => 'Third'],
                ],
            ], 200),
    ]);

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'secret-token',
        'fields' => 'id,name',
        'limit' => 100,
    ]);

    expect($pages)->toHaveCount(3)
        ->and(data_get($pages, '0.id'))->toBe('page_1')
        ->and(data_get($pages, '1.id'))->toBe('page_2')
        ->and(data_get($pages, '2.id'))->toBe('page_3');

    Http::assertSentCount(3);
});

test('graph paginator collects authorized page when first response is empty', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $nextUrl = "{$graphApi}/me/accounts?access_token=token&after=cursor1&limit=100";

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    ['id' => 'page_desired', 'name' => 'Desired Page'],
                ],
            ], 200),
    ]);

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
        'limit' => 100,
    ]);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_desired');
});

test('graph paginator throws when a later request fails after earlier pages succeeded', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $nextUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor1&limit=100";

    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Meta Graph pagination request failed'
            && ! str_contains((string) data_get($context, 'url'), 'secret-token')
            && str_contains((string) data_get($context, 'url'), 'access_token=[REDACTED]');
    });

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 'page_1', 'name' => 'First'],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200)
            ->push(['error' => ['message' => 'rate limit']], 400),
    ]);

    GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'secret-token',
        'limit' => 100,
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('graph paginator throws when the first request fails', function () {
    Http::preventStrayRequests();

    Log::shouldReceive('error')->once()->withArgs(function (string $message) {
        return $message === 'Meta Graph pagination request failed';
    });

    Http::fake([
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    GraphPaginator::all('https://graph.facebook.com/v25.0/me/accounts', [
        'access_token' => 'token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('graph paginator throws when the first request cannot connect', function () {
    Http::preventStrayRequests();

    Log::shouldReceive('error')->once()->withArgs(function (string $message) {
        return $message === 'Meta Graph pagination connection failed';
    });

    Http::fake([
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::failedConnection(),
    ]);

    GraphPaginator::all('https://graph.facebook.com/v25.0/me/accounts', [
        'access_token' => 'token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);
test('graph paginator throws when a later request cannot connect', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $nextUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor1&limit=100";
    $requestCount = 0;

    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Meta Graph pagination connection failed'
            && str_contains((string) data_get($context, 'url'), 'access_token=[REDACTED]');
    });

    Http::fake(function () use (&$requestCount, $nextUrl) {
        $requestCount++;

        if ($requestCount === 1) {
            return Http::response([
                'data' => [
                    ['id' => 'page_1'],
                ],
                'paging' => [
                    'next' => $nextUrl,
                ],
            ], 200);
        }

        return Http::failedConnection();
    });

    GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'secret-token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('graph paginator stops when paging.next is not a usable string', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                ['id' => 'page_1'],
            ],
            'paging' => [
                'next' => ['broken' => true],
            ],
        ], 200),
    ]);

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
    ]);

    expect($pages)->toHaveCount(1);
    Http::assertSentCount(1);
});

test('graph paginator throws when paging.next repeats after pages were fetched', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $loopUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor&limit=100";

    Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) {
        return $message === 'Meta Graph pagination stopped: repeated paging URL'
            && ! str_contains((string) data_get($context, 'url'), 'secret-token')
            && str_contains((string) data_get($context, 'url'), 'access_token=[REDACTED]');
    });

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::sequence()
            ->push([
                'data' => [
                    ['id' => 'page_1'],
                ],
                'paging' => [
                    'next' => $loopUrl,
                ],
            ], 200)
            ->push([
                'data' => [
                    ['id' => 'page_2'],
                ],
                'paging' => [
                    'next' => $loopUrl,
                ],
            ], 200),
    ]);

    GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('graph paginator throws when paging.next points to another host', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';

    Log::shouldReceive('error')->once()->withArgs(function (string $message) {
        return $message === 'Meta Graph pagination stopped: unexpected paging host';
    });

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [
                ['id' => 'page_1'],
            ],
            'paging' => [
                'next' => 'https://evil.example/me/accounts?access_token=secret-token',
            ],
        ], 200),
    ]);

    GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('graph paginator throws when the safety page ceiling is reached', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $requestCount = 0;

    Log::shouldReceive('error')->once()->withArgs(function (string $message) {
        return $message === 'Meta Graph pagination stopped: page limit reached';
    });

    Http::fake(function () use (&$requestCount, $graphApi) {
        $requestCount++;
        $after = $requestCount;

        return Http::response([
            'data' => [
                ['id' => "page_{$requestCount}"],
            ],
            'paging' => [
                'next' => "{$graphApi}/me/accounts?access_token=token&after={$after}",
            ],
        ], 200);
    });

    GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);
