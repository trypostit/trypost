<?php

declare(strict_types=1);

use App\Actions\Admin\BuildPublishingStats;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->addHours(12));
});

function publishedOn(SocialAccount $channel, CarbonImmutable $at, string $state = 'published', ?string $error = null): Post
{
    $post = Post::factory()->forAccount($channel)->{$state}()->create();
    Post::query()->whereKey($post->id)->toBase()->update(['publication_updated_at' => $at, 'error_message' => $error]);

    return $post;
}

test('counts published and failed posts per day and network', function () {
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    $linkedin = SocialAccount::factory()->create(['platform' => Platform::LinkedIn]);
    $today = CarbonImmutable::now('UTC');
    $yesterday = $today->subDay();

    publishedOn($x, $today);
    publishedOn($x, $today, 'failed', 'Rate limit');
    publishedOn($linkedin, $yesterday);
    publishedOn($linkedin, $yesterday, 'deletedOnNetwork');

    $stats = BuildPublishingStats::execute($today->subDays(6)->toDateString(), $today->toDateString(), 'UTC');

    $days = collect($stats['days'])->keyBy('date');

    expect($stats['days'])->toHaveCount(7)
        ->and($days[$today->toDateString()])->toMatchArray(['published' => 1, 'failed' => 1])
        ->and($days[$yesterday->toDateString()])->toMatchArray(['published' => 2, 'failed' => 0])
        ->and($stats['totals'])->toMatchArray(['published' => 3, 'failed' => 1])
        ->and(collect($stats['networks'])->firstWhere('platform', 'x'))->toMatchArray(['published' => 1, 'failed' => 1, 'failure_rate' => 50.0])
        ->and(collect($stats['networks'])->firstWhere('platform', 'linkedin'))->toMatchArray(['published' => 2, 'failed' => 0]);
});

test('ignores posts outside the range, imported posts and posts that did not finish', function () {
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);

    publishedOn($x, CarbonImmutable::now('UTC')->subDays(8));
    $imported = Post::factory()->forAccount($x)->imported()->create();
    $publishing = Post::factory()->forAccount($x)->publishing()->create();
    Post::factory()->forAccount($x)->scheduled()->create();
    Post::query()->whereKey([$imported->id, $publishing->id])->toBase()->update(['publication_updated_at' => now()]);

    $today = CarbonImmutable::now('UTC');

    expect(BuildPublishingStats::execute($today->subDays(6)->toDateString(), $today->toDateString(), 'UTC')['totals'])->toMatchArray(['published' => 0, 'failed' => 0]);
});

test('filters by one or more platforms', function () {
    $today = CarbonImmutable::now('UTC')->toDateString();
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::X]), CarbonImmutable::now('UTC'));
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::LinkedIn]), CarbonImmutable::now('UTC'));
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::LinkedInPage]), CarbonImmutable::now('UTC'));
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::Threads]), CarbonImmutable::now('UTC'));

    expect(BuildPublishingStats::execute($today, $today, 'UTC', [Platform::X])['totals'])->toMatchArray(['published' => 1])
        ->and(BuildPublishingStats::execute($today, $today, 'UTC', [Platform::X, Platform::LinkedIn, Platform::LinkedInPage])['totals'])->toMatchArray(['published' => 3]);
});

test('groups the most frequent errors per network', function () {
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    $now = CarbonImmutable::now('UTC');

    publishedOn($x, $now, 'failed', 'Rate limit');
    publishedOn($x, $now, 'failed', 'Rate limit');
    publishedOn($x, $now, 'failed', 'Token expired');

    expect(BuildPublishingStats::execute($now->toDateString(), $now->toDateString(), 'UTC')['top_errors'])->toBe([
        ['platform' => 'x', 'network' => 'x', 'category' => 'unknown', 'message' => 'Rate limit', 'count' => 2],
        ['platform' => 'x', 'network' => 'x', 'category' => 'unknown', 'message' => 'Token expired', 'count' => 1],
    ]);
});

test('the admin posts page reads the range and networks like Insights', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $admin = User::factory()->create(['email' => 'admin@trypost.test']);
    $today = CarbonImmutable::now('UTC');
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::LinkedInPage]), $today);
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::X]), $today);

    $this->actingAs($admin)
        ->get(route('admin.posts.index', ['range' => '7d', 'networks' => ['linkedin']]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/posts/Index')
            ->has('stats.days', 7)
            ->where('stats.totals.published', 1)
            ->where('filters.range', '7d')
            ->where('filters.networks', ['linkedin'])
            ->where('range.end', $today->toDateString()),
        );

    $this->actingAs($admin)
        ->get(route('admin.posts.index', ['range' => 'custom', 'start' => $today->subDays(2)->toDateString(), 'end' => $today->toDateString()]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('stats.days', 3));

    $this->actingAs($admin)
        ->get(route('admin.posts.index', ['range' => 'custom']))
        ->assertSessionHasErrors(['start', 'end']);

    $this->actingAs($admin)
        ->get(route('admin.posts.index', ['networks' => ['myspace']]))
        ->assertSessionHasErrors('networks.0');
});

test('the by-network table puts every way of connecting a network together', function () {
    $now = CarbonImmutable::now('UTC');
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::Instagram]), $now);
    publishedOn(SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook]), $now);

    $networks = collect(BuildPublishingStats::execute($now->toDateString(), $now->toDateString(), 'UTC')['networks']);

    expect($networks)->toHaveCount(1)
        ->and($networks->sole())->toMatchArray(['platform' => 'instagram', 'published' => 2]);
});

test('breaks failures down by cause, posts by origin and by post type', function () {
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    $today = CarbonImmutable::now('UTC');

    $rateLimited = publishedOn($x, $today, 'failed', 'Too many');
    Post::query()->whereKey($rateLimited->id)->toBase()->update(['error_context' => json_encode(['category' => 'rate_limit']), 'created_via' => 'api']);
    publishedOn($x, $today, 'failed', 'Odd');
    $viaMcp = publishedOn($x, $today);
    Post::query()->whereKey($viaMcp->id)->toBase()->update(['created_via' => 'mcp']);

    $stats = BuildPublishingStats::execute($today->toDateString(), $today->toDateString(), 'UTC');

    expect(collect($stats['categories'])->pluck('count', 'category')->all())->toEqual(['rate_limit' => 1, 'unknown' => 1])
        ->and(collect($stats['origins'])->pluck('published', 'origin')->all())->toEqual(['api' => 0, 'mcp' => 1, 'web' => 0])
        ->and(collect($stats['top_errors'])->firstWhere('message', 'Too many'))->toMatchArray(['category' => 'rate_limit', 'network' => 'x'])
        ->and($stats['content_types'][0])->toMatchArray(['platform' => 'x', 'platform_label' => 'X', 'published' => 1, 'failed' => 2]);
});

test('counts each post on the day it went out in the viewer\'s zone', function () {
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    $today = CarbonImmutable::now('UTC')->startOfDay();
    publishedOn($x, $today->addHour());

    $saoPaulo = BuildPublishingStats::execute($today->subDay()->toDateString(), $today->toDateString(), 'America/Sao_Paulo');
    $utc = BuildPublishingStats::execute($today->subDay()->toDateString(), $today->toDateString(), 'UTC');

    expect(collect($saoPaulo['days'])->pluck('published')->all())->toBe([1, 0])
        ->and(collect($utc['days'])->pluck('published')->all())->toBe([0, 1]);
});
