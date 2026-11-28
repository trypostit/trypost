<?php

declare(strict_types=1);

use App\Actions\Admin\BuildQuotaCards;
use App\Dto\Analytics\DateRange;
use App\Enums\Admin\QuotaSource;
use App\Support\Admin\QuotaSnapshots;
use App\Support\Admin\XPricing;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.admin.quotas.youtube', [
        'enabled' => true,
        'project_id' => 'trypost-test',
        'credentials' => 'secret',
        'timezone' => 'America/Los_Angeles',
    ]);
    config()->set('trypost.admin.quotas.x', ['enabled' => true, 'bearer_token' => 'x-bearer']);
});

test('the cards show the latest readings exactly as the providers reported them', function () {
    $youtube = ['quotas' => [['metric' => 'youtube.googleapis.com/video_insert', 'usage' => 25, 'limits' => [['name' => 'defaultVideoInsertPerDayPerProject', 'value' => 100]]]], 'since' => now('America/Los_Angeles')->startOfDay()->utc()->toIso8601String()];
    $x = ['credits' => ['total_balance' => 54.2], 'usage' => ['project_usage' => 12254]];
    QuotaSnapshots::put(QuotaSource::YouTube, $youtube);
    QuotaSnapshots::put(QuotaSource::X, $x);

    $cards = BuildQuotaCards::execute(DateRange::fromDates(now('UTC')->subDays(29)->toDateString(), now('UTC')->toDateString(), 'UTC'));

    expect($cards['youtube'])->toMatchArray(['configured' => true, 'quotas' => $youtube['quotas'], 'since' => $youtube['since']])
        ->and($cards['x'])->toMatchArray(['configured' => true, 'credits' => $x['credits']])
        ->and($cards['x']['usage']['project_usage'])->toBe(12254)
        ->and($cards['x'])->not->toHaveKeys(['spend_today', 'posts_today'])
        ->and($cards['youtube'])->not->toHaveKey('uploads_today');
});

test('the x card estimates the cost of every day of the range', function () {
    $today = now('UTC')->toDateString();
    $yesterday = now('UTC')->subDay()->toDateString();
    QuotaSnapshots::put(QuotaSource::X, ['usage' => ['daily_project_usage' => [
        ['date' => $yesterday, 'usage' => 200],
        ['date' => $today, 'usage' => 100],
    ]]]);

    $x = BuildQuotaCards::execute(DateRange::fromDates(now('UTC')->subDays(29)->toDateString(), $today, 'UTC'))['x'];

    expect($x['estimate'])->toHaveCount(30)
        ->and(collect($x['estimate'])->last()['date'])->toBe($today)
        ->and(collect($x['estimate'])->firstWhere('date', $yesterday)['reads'])->toBe(200)
        ->and($x['prices'])->toBe([
            'read' => XPricing::POST_READ,
            'create' => XPricing::POST_CREATE,
            'create_with_link' => XPricing::POST_CREATE_WITH_URL,
        ]);
});

test('the x daily usage is cut to the chosen range', function () {
    $today = now('UTC')->toDateString();
    $old = now('UTC')->subDays(40)->toDateString();
    QuotaSnapshots::put(QuotaSource::X, ['usage' => ['project_usage' => 1, 'daily_project_usage' => [
        ['date' => $old, 'usage' => 999],
        ['date' => $today, 'usage' => 100],
    ]]]);

    $cards = BuildQuotaCards::execute(DateRange::fromDates(now('UTC')->subDays(6)->toDateString(), $today, 'UTC'));

    expect($cards['x']['usage']['daily_project_usage'])->toBe([['date' => $today, 'usage' => 100]])
        ->and($cards['x']['estimate'])->toHaveCount(7);
});

test('the quotas page shows 7, 30, 60 or 90 days of X, 30 by default', function () {
    $this->actingAs(admin());

    $this->get(route('admin.quotas.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.range', '30d')
            ->where('ranges', ['7d', '30d', '60d', '90d']));

    $this->get(route('admin.quotas.index', ['range' => '90d']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.range', '90d')->has('cards.x.estimate', 90));

    $this->get(route('admin.quotas.index', ['range' => 'custom']))
        ->assertSessionHasErrors('range');
});

test('a source that is off reads as not configured', function () {
    config()->set('trypost.admin.quotas.youtube.enabled', false);
    config()->set('trypost.admin.quotas.x.bearer_token', null);

    $cards = BuildQuotaCards::execute(DateRange::fromDates(now('UTC')->subDays(29)->toDateString(), now('UTC')->toDateString(), 'UTC'));

    expect($cards['youtube']['configured'])->toBeFalse()
        ->and($cards['x']['configured'])->toBeFalse();
});

test('the admin quotas page renders both cards', function () {
    $this->actingAs(admin())
        ->get(route('admin.quotas.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/quotas/Index')
            ->has('cards.youtube')
            ->has('cards.x'),
        );
});
