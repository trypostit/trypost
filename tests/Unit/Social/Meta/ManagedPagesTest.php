<?php

declare(strict_types=1);

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use App\Services\Social\Meta\ManagedPages;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const MANAGED_PAGES_FIELDS = 'id,name,access_token';

beforeEach(function () {
    Http::preventStrayRequests();
});

function managedPagesGraphApi(): string
{
    return (string) config('trypost.platforms.facebook.graph_api');
}

test('business portfolio pages are found when me/accounts is empty', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Owned Page', 'access_token' => 'owned-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response([
            'data' => [['id' => 'page_2', 'name' => 'Client Page', 'access_token' => 'client-token']],
        ], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(2)
        ->and(data_get($pages, '0.id'))->toBe('page_1')
        ->and(data_get($pages, '1.id'))->toBe('page_2');
});

test('a page listed in both me/accounts and a portfolio is returned once, keeping its user token', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'portfolio-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.access_token'))->toBe('role-token');
});

test('every page meta lists is returned, token or not', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
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

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect(collect($pages)->pluck('id')->sort()->values()->all())->toBe(['page_1', 'page_2']);
});

test('a page reached with a token wins over the same page reached without one', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'No Token Here']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Same Page', 'access_token' => 'portfolio-token']],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.access_token'))->toBe('portfolio-token')
        ->and(ManagedPages::publishable($pages))->toHaveCount(1);
});

test('only the pages carrying a token are publishable', function () {
    $publishable = ManagedPages::publishable([
        ['id' => 'page_1', 'name' => 'No Access'],
        ['id' => 'page_2', 'name' => 'Usable', 'access_token' => 'page-token'],
        ['id' => 'page_3', 'name' => 'Empty Token', 'access_token' => ''],
    ]);

    expect(collect($publishable)->pluck('id')->all())->toBe(['page_2']);
});

test('a login without business_management keeps the pages me/accounts returned', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'error' => ['message' => 'Requires business_management permission'],
        ], 403),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_1');
});

test('a failing portfolio edge keeps the pages me/accounts already returned', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response(['error' => ['message' => 'nope']], 400),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['error' => ['message' => 'nope']], 400),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_1');
});

test('a failing me/accounts still aborts instead of reporting no pages', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['error' => ['message' => 'fail']], 400),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('pages spread across several portfolios are all collected', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
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

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect(collect($pages)->pluck('id')->all())->toBe(['page_1', 'page_2']);
});

test('a paginated portfolio edge is followed to the end', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
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

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect(collect($pages)->pluck('id')->all())->toBe(['page_1', 'page_2']);
});

test('a portfolio entry without an id is skipped', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['name' => 'No Id']]], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'owned_pages'));
});

test('a throttled portfolio edge is raised rather than read as no pages', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'error' => ['message' => 'Application request limit reached', 'code' => 4],
        ], 400),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('an upstream failure listing portfolios is raised rather than read as no portfolios', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response(['error' => ['message' => 'oops']], 500),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('a portfolio edge denied by permissions reads as no pages', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
        "{$graphApi}/me/businesses*" => Http::response([
            'error' => ['message' => 'Requires business_management permission', 'code' => 200],
        ], 403),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(1)
        ->and(data_get($pages, '0.id'))->toBe('page_1');
});

test('the portfolio walk refuses to hand back a list it could not finish', function () {
    $graphApi = managedPagesGraphApi();
    $portfolios = collect(range(1, ManagedPages::MAX_PORTFOLIOS + 1))
        ->map(fn (int $n) => ['id' => "biz_{$n}"])
        ->all();

    Log::shouldReceive('error')
        ->once()
        ->with('Meta portfolio walk stopped: ceiling reached', [
            'found' => ManagedPages::MAX_PORTFOLIOS + 1,
            'ceiling' => ManagedPages::MAX_PORTFOLIOS,
        ]);

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => $portfolios], 200),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('portfolio edges are read concurrently rather than one after another', function () {
    $graphApi = managedPagesGraphApi();
    $portfolios = collect(range(1, 30))->map(fn (int $n) => ['id' => "biz_{$n}"])->all();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => $portfolios], 200),
        "{$graphApi}/*_pages*" => Http::response(['data' => []], 200),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

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

    Http::fake($fakes);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);

    expect($pages)->toHaveCount(26)
        ->and(collect($pages)->pluck('id')->sort()->values()->all())
        ->toBe(collect(range(1, 26))->map(fn (int $n) => "page_{$n}")->sort()->values()->all());
});

test('a portfolio edge paging off-host never gets the token', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::response(['data' => [['id' => 'biz_1']]], 200),
        "{$graphApi}/biz_1/owned_pages*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'One', 'access_token' => 'token-1']],
            'paging' => ['next' => 'https://evil.example/owned_pages?access_token=user-token'],
        ], 200),
        "{$graphApi}/biz_1/client_pages*" => Http::response(['data' => []], 200),
    ]);

    try {
        ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
    } catch (IncompleteMetaGraphPaginationException) {
        // The off-host walk aborts; what matters is where the token did not go.
    }

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});

test('a cursor that fails after the first page is raised, not read as the whole edge', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
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

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('a portfolio list cut short mid-walk never silently drops the portfolios it did read', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response(['data' => []], 200),
        "{$graphApi}/me/businesses*" => Http::sequence()
            ->push([
                'data' => [['id' => 'biz_1']],
                'paging' => ['next' => "{$graphApi}/me/businesses?access_token=user-token&after=cursor1"],
            ], 200)
            ->push(['error' => ['message' => 'Invalid cursor', 'code' => 100]], 400),
    ]);

    ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS);
})->throws(IncompleteMetaGraphPaginationException::class);

test('a login meta reports as refusing business_management never touches the portfolio edges', function () {
    $graphApi = managedPagesGraphApi();

    Http::fake([
        "{$graphApi}/me/accounts*" => Http::response([
            'data' => [['id' => 'page_1', 'name' => 'Page', 'access_token' => 'role-token']],
        ], 200),
    ]);

    $pages = ManagedPages::forUser($graphApi, 'user-token', MANAGED_PAGES_FIELDS, ['pages_show_list']);

    expect($pages)->toHaveCount(1);
    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/me/businesses'));
});
