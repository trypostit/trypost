<?php

declare(strict_types=1);

use App\Models\RssFeed;
use App\Models\User;
use App\Models\Workspace;
use App\Support\RssFeedUrl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function rssFeedDuplicateFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);

    $this->existing = RssFeed::factory()->create([
        'workspace_id' => $this->workspace->id,
        'url' => 'https://93.184.216.34/feed.xml',
    ]);
});

test('a spelling of an existing feed is refused before any request', function (string $url) {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => $url])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.already_added')]);

    Http::assertNothingSent();
    expect(RssFeed::query()->count())->toBe(1);
})->with([
    'http' => 'http://93.184.216.34/feed.xml',
    'trailing slash' => 'https://93.184.216.34/feed.xml/',
    'default port' => 'https://93.184.216.34:443/feed.xml',
    'fragment' => 'https://93.184.216.34/feed.xml#x',
    'no scheme' => '93.184.216.34/feed.xml',
]);

test('a url that redirects to an existing feed is refused', function () {
    Http::fake([
        'https://93.184.216.34/old-feed' => Http::response('', 301, ['Location' => 'https://93.184.216.34/feed.xml']),
        'https://93.184.216.34/feed.xml' => Http::response(rssFeedDuplicateFixture('announcekit_rss.xml')),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'https://93.184.216.34/old-feed'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.already_added')]);

    expect(RssFeed::query()->count())->toBe(1);
});

test('a site whose discovered feed already exists is refused', function () {
    Http::fake([
        'https://93.184.216.34/' => Http::response(rssFeedDuplicateFixture('html_with_alternate.html')),
        'https://93.184.216.34/feed.xml' => Http::response(rssFeedDuplicateFixture('announcekit_rss.xml')),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'https://93.184.216.34/'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.already_added')]);

    expect(RssFeed::query()->count())->toBe(1);
});

test('the directory add of an existing feed is refused', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'https://93.184.216.34/feed.xml', 'source' => 'explore'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.already_added')]);

    expect(RssFeed::query()->count())->toBe(1);
});

test('a feed added by a concurrent request after the checks becomes the same error', function () {
    Http::fake(['https://1.1.1.2/feed.xml' => Http::response(rssFeedDuplicateFixture('announcekit_rss.xml'))]);
    $workspace = $this->workspace;
    $hash = RssFeedUrl::hash('https://1.1.1.2/feed.xml');
    $checks = 0;
    $raced = false;

    DB::listen(function (QueryExecuted $query) use ($workspace, $hash, &$checks, &$raced): void {
        if ($raced || ! in_array($hash, $query->bindings, true) || ! str_starts_with(strtolower($query->sql), 'select exists')) {
            return;
        }

        if (++$checks === 2) {
            $raced = true;
            RssFeed::factory()->create(['workspace_id' => $workspace->id, 'url' => 'https://1.1.1.2/feed.xml/']);
        }
    });

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'https://1.1.1.2/feed.xml'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.already_added')]);

    expect($raced)->toBeTrue()
        ->and(RssFeed::query()->where('url_hash', $hash)->count())->toBe(1)
        ->and(RssFeed::query()->where('url_hash', $hash)->sole()->url)->toBe('https://1.1.1.2/feed.xml/');
});

test('the same feed can be added to another workspace', function () {
    Http::fake(['https://93.184.216.34/feed.xml' => Http::response(rssFeedDuplicateFixture('announcekit_rss.xml'))]);
    $other = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $other->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $other->id]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.create.feeds.store'), ['url' => 'https://93.184.216.34/feed.xml'])
        ->assertSessionHasNoErrors();

    expect(RssFeed::query()->count())->toBe(2);
});
