<?php

declare(strict_types=1);

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\Meta\ManagedPageList;
use App\Services\Social\Meta\ManagedPages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const MANAGED_PAGES_FIELDS = 'id,name,access_token';

beforeEach(function () {
    Http::preventStrayRequests();
    Log::spy();
});

function managedPagesGraphApi(): string
{
    return (string) config('trypost.platforms.facebook.graph_api');
}

function managedPagesWalk(array $extraFakes = [], array $granted = ['business_management']): ManagedPageList
{
    Http::fake($extraFakes);

    return ManagedPages::forUser(managedPagesGraphApi(), 'user-token', MANAGED_PAGES_FIELDS, $granted);
}

function managedPagesIds(ManagedPageList $walk): array
{
    return collect($walk->pages)->pluck('id')->all();
}

test('business portfolio pages are found when me/accounts is empty', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Owned', 'access_token' => 'owned-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response([
            'data' => [['id' => 'page_2', 'name' => 'Client', 'access_token' => 'client-token']],
        ], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1', 'page_2'])
        ->and($walk->complete)->toBeTrue();
});

test('a page listed in both me/accounts and a portfolio is returned once, keeping its user token', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'portfolio-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect($walk->pages)->toHaveCount(1)
        ->and(data_get($walk->pages, '0.access_token'))->toBe('role-token');
});

test('a page reached with a token wins over the same page reached without one', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'No Token Here']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Same Page', 'access_token' => 'portfolio-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect($walk->pages)->toHaveCount(1)
        ->and(data_get($walk->pages, '0.access_token'))->toBe('portfolio-token')
        ->and(ManagedPages::publishable($walk->pages))->toHaveCount(1);
});

test('every page meta lists is returned, token or not', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [
                ['id' => 'page_1', 'name' => 'No Access'],
                ['id' => 'page_2', 'name' => 'Usable', 'access_token' => 'page-token'],
            ],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(collect($walk->pages)->pluck('id')->sort()->values()->all())->toBe(['page_1', 'page_2']);
});

test('only the pages carrying a token are publishable', function () {
    $publishable = ManagedPages::publishable([
        ['id' => 'page_1', 'name' => 'No Access'],
        ['id' => 'page_2', 'name' => 'Usable', 'access_token' => 'page-token'],
        ['id' => 'page_3', 'name' => 'Empty Token', 'access_token' => ''],
    ]);

    expect(collect($publishable)->pluck('id')->all())->toBe(['page_2']);
});

test('a login meta reports as refusing business_management never touches the portfolio edges', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
    ], granted: ['pages_show_list']);

    expect($walk->pages)->toHaveCount(1)
        ->and($walk->complete)->toBeTrue();

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/businesses'));
});

test('a refused portfolio index says nothing about the pages behind it', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'error' => ['message' => 'Requires business_management permission', 'code' => 200],
        ], 403),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('a throttled portfolio index leaves the walk unable to vouch for itself', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'error' => ['message' => 'Application request limit reached', 'code' => 4],
        ], 400),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('the walk gives up on time rather than outliving the request', function () {
    config()->set('trypost.meta_page_walk_seconds', 0);

    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '_pages'));
});

test('a refused single edge is an answer about that edge, and leaves the walk complete', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Owned', 'access_token' => 'token-1']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response([
            'error' => ['message' => 'permission denied', 'code' => 10],
        ], 403),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeTrue();
});

test('a continuation cut short keeps the pages it already read', function () {
    $graphApi = managedPagesGraphApi();
    $cursor = "{$graphApi}/biz_1/owned_pages?access_token=user-token&after=";

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::sequence()
            ->push([
                'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
                'paging' => ['next' => "{$cursor}c1"],
            ], 200)
            ->push([
                'data' => [['id' => 'page_2', 'name' => 'Two', 'access_token' => 'token-2']],
                'paging' => ['next' => "{$cursor}c2"],
            ], 200)
            ->push(['error' => ['message' => 'Invalid cursor', 'code' => 100]], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1', 'page_2'])
        ->and($walk->complete)->toBeFalse();
});

test('the cursor budget counts requests, not edges', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_x', 'name' => 'X', 'access_token' => 'token']],
            'paging' => ['next' => "{$graphApi}/biz_1/owned_pages?access_token=user-token&after=forever"],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect($walk->complete)->toBeFalse();

    expect(collect(Http::recorded())->filter(
        fn (array $pair) => str_contains($pair[0]->url(), 'after=forever'),
    ))->toHaveCount(ManagedPages::MAX_CONTINUATIONS);
});

test('a throttled portfolio edge keeps the pages it has and admits it is incomplete', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'error' => ['message' => 'Application request limit reached', 'code' => 4],
        ], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('an upstream failure listing portfolios keeps the me/accounts pages', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['error' => ['message' => 'oops']], 500),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('a failing me/accounts still aborts instead of reporting no pages', function () {
    $graphApi = managedPagesGraphApi();

    managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['error' => ['message' => 'fail']], 400),
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('pages spread across several portfolios are all collected', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'data' => [['id' => 'biz_1'], ['id' => 'biz_2']],
        ], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
        "{$graphApi}/biz_2/owned_pages*" => Http::response(['data' => []], 200),
        "{$graphApi}/biz_2/client_pages*" => Http::response([
            'data' => [['id' => 'page_2', 'name' => 'Two', 'access_token' => 'token-2']],
        ], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1', 'page_2'])
        ->and($walk->complete)->toBeTrue();
});

test('a paginated portfolio edge is followed to the end', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::sequence()
            ->push([
                'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
                'paging' => ['next' => "{$graphApi}/biz_1/owned_pages?access_token=user-token&after=cursor1"],
            ], 200)
            ->push([
                'data' => [['id' => 'page_2', 'name' => 'Two', 'access_token' => 'token-2']],
            ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1', 'page_2'])
        ->and($walk->complete)->toBeTrue();
});

test('a cursor that fails after the first page keeps that page and admits it is incomplete', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::sequence()
            ->push([
                'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
                'paging' => ['next' => "{$graphApi}/biz_1/owned_pages?access_token=user-token&after=cursor1"],
            ], 200)
            ->push(['error' => ['message' => 'Invalid cursor', 'code' => 100]], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('a portfolio entry without an id is skipped', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['name' => 'No Id']]], 200),
    ]);

    expect($walk->pages)->toHaveCount(1)
        ->and($walk->complete)->toBeTrue();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'owned_pages'));
});

test('more portfolios than the walk reads is an incomplete walk, not a failed one', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'data' => [['id' => 'biz_1']],
            'paging' => ['next' => "{$graphApi}/me/businesses?access_token=user-token&after=cursor1"],
        ], 200),
        "{$graphApi}/biz_1/*_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});

test('the portfolio list is read in one request, never paginated', function () {
    $graphApi = managedPagesGraphApi();

    managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'data' => [['id' => 'biz_1']],
            'paging' => ['next' => "{$graphApi}/me/businesses?access_token=user-token&after=cursor1"],
        ], 200),
        "{$graphApi}/biz_1/*_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(collect(Http::recorded())->filter(
        fn (array $pair) => str_contains($pair[0]->url(), '/me/businesses'),
    ))->toHaveCount(1);
});

test('portfolio edges are read concurrently rather than one after another', function () {
    $graphApi = managedPagesGraphApi();
    $portfolios = collect(range(1, 30))->map(fn (int $n) => ['id' => "biz_{$n}"])->all();

    managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => $portfolios], 200),
        "{$graphApi}/*_pages*" => Http::response(['data' => []], 200),
    ]);

    Http::assertSentCount(2 + (30 * 2));
});

test('pages from every round survive the merge, not just the first', function () {
    $graphApi = managedPagesGraphApi();
    $portfolios = collect(range(1, 26))->map(fn (int $n) => ['id' => "biz_{$n}"])->all();

    $fakes = [
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => $portfolios], 200),
    ];

    foreach (range(1, 26) as $n) {
        $fakes["{$graphApi}/biz_{$n}/owned_pages*"] = Http::response([
            'data' => [['id' => "page_{$n}", 'name' => "Page {$n}", 'access_token' => "token-{$n}"]],
        ], 200);
        $fakes["{$graphApi}/biz_{$n}/client_pages*"] = Http::response(['data' => []], 200);
    }

    $walk = managedPagesWalk($fakes);

    expect($walk->pages)->toHaveCount(26)
        ->and(collect($walk->pages)->pluck('id')->sort()->values()->all())
        ->toBe(collect(range(1, 26))->map(fn (int $n) => "page_{$n}")->sort()->values()->all());
});

test('a portfolio edge paging off-host never gets the token', function () {
    $graphApi = managedPagesGraphApi();

    managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
            'paging' => ['next' => 'https://evil.example/owned_pages?access_token=user-token'],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});

test('an off-host cursor stops the edge instead of re-reading it', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
            'paging' => ['next' => 'https://evil.example/owned_pages?access_token=user-token'],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
    expect(collect(Http::recorded())->filter(
        fn (array $pair) => str_contains($pair[0]->url(), 'owned_pages'),
    ))->toHaveCount(1);
});

test('the cursor budget stops a walk that would never end', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'data' => collect(range(1, ManagedPages::MAX_CONTINUATIONS + 5))
                ->map(fn (int $n) => ['id' => "biz_{$n}"])
                ->all(),
        ], 200),
        "{$graphApi}/*_pages*" => Http::response([
            'data' => [['id' => 'page_x', 'name' => 'X', 'access_token' => 'token']],
            'paging' => ['next' => "{$graphApi}/biz_1/owned_pages?access_token=user-token&after=cursor"],
        ], 200),
    ]);

    expect($walk->complete)->toBeFalse();
});

test('the deadline stops me/accounts from paginating forever', function () {
    config()->set('trypost.meta_page_walk_seconds', 0);

    $graphApi = managedPagesGraphApi();

    managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
            'paging' => ['next' => "{$graphApi}/me/accounts?access_token=user-token&after=c1"],
        ], 200),
    ]);
})->throws(IncompleteMetaGraphPaginationException::class);

test('a pages-api throttle on a user token is a throttle, not an answer', function () {
    $graphApi = managedPagesGraphApi();

    $walk = managedPagesWalk([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'error' => ['message' => 'Page request limit reached', 'code' => 32],
        ], 400),
    ]);

    expect(managedPagesIds($walk))->toBe(['page_1'])
        ->and($walk->complete)->toBeFalse();
});
