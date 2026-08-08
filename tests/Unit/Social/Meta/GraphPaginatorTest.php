<?php

declare(strict_types=1);

use App\Services\Social\Meta\GraphPaginator;
use Illuminate\Support\Facades\Http;

test('graph paginator follows paging.next until exhausted', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $nextUrl = "{$graphApi}/me/accounts?access_token=token&after=cursor1&limit=100";

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
            ], 200),
    ]);

    $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
        'access_token' => 'token',
        'fields' => 'id,name',
        'limit' => 100,
    ]);

    expect($pages)->toHaveCount(2)
        ->and(data_get($pages, '0.id'))->toBe('page_1')
        ->and(data_get($pages, '1.id'))->toBe('page_2');

    Http::assertSentCount(2);
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

test('graph paginator returns empty array when the first request fails', function () {
    Http::preventStrayRequests();

    Http::fake([
        'https://graph.facebook.com/v25.0/me/accounts*' => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    $pages = GraphPaginator::all('https://graph.facebook.com/v25.0/me/accounts', [
        'access_token' => 'token',
    ]);

    expect($pages)->toBe([]);
});

test('graph paginator stops when paging.next repeats to avoid infinite loops', function () {
    Http::preventStrayRequests();

    $graphApi = 'https://graph.facebook.com/v25.0';
    $loopUrl = "{$graphApi}/me/accounts?access_token=token&after=cursor&limit=100";

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
