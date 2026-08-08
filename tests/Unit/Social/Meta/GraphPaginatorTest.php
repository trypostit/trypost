<?php

declare(strict_types=1);

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
    Http::assertSent(fn (Request $request) => $request['limit'] === 100 && $request['access_token'] === 'token');
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

test('graph paginator keeps earlier pages when a later request fails', function () {
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

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'secret-token',
        'limit' => 100,
    ]);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_1');

    Http::assertSentCount(2);
});

test('graph paginator returns empty array when the first request fails', function () {
    Http::preventStrayRequests();

    Log::shouldReceive('error')->once()->withArgs(function (string $message) {
        return $message === 'Meta Graph pagination request failed';
    });

    Http::fake([
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    $pages = GraphPaginator::all('https://graph.facebook.com/v25.0/me/accounts', [
        'access_token' => 'token',
    ]);

    expect($pages)->toBe([]);
});

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

test('graph paginator stops when paging.next repeats to avoid infinite loops', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $loopUrl = "{$graphApi}/me/accounts?access_token=secret-token&after=cursor&limit=100";

    Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context) {
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

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
    ]);

    expect($pages)->toHaveCount(2)
        ->and(data_get($pages, '0.id'))->toBe('page_1')
        ->and(data_get($pages, '1.id'))->toBe('page_2');

    Http::assertSentCount(2);
});
