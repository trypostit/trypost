<?php

declare(strict_types=1);

use App\Actions\Admin\BuildDashboard;
use App\Enums\Admin\QuotaSource;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Admin\QuotaSnapshots;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('sums up the last 24 hours of publishing and the channels to reconnect', function () {
    $now = CarbonImmutable::now('UTC');

    $channel = SocialAccount::factory()->create(['platform' => Platform::X]);
    SocialAccount::factory()->create(['status' => Status::TokenExpired]);

    foreach (['published', 'published', 'failed'] as $state) {
        channelPostAt($channel, $now->subHour(), $state);
    }

    $dashboard = BuildDashboard::execute('UTC');

    expect($dashboard)->not->toHaveKeys(['users', 'accounts'])
        ->and($dashboard['last_24h'])->toMatchArray(['published' => 2, 'failed' => 1])
        ->and($dashboard['channels'])->toMatchArray(['to_reconnect' => 1])
        ->and($dashboard['publishing_days'])->toHaveCount(14);
});

test('flags a network failing often, posts stuck publishing and quotas running out', function () {
    $now = CarbonImmutable::now('UTC');
    $failing = SocialAccount::factory()->create(['platform' => Platform::LinkedInPage]);
    $noisy = SocialAccount::factory()->create(['platform' => Platform::GoogleBusiness]);

    foreach (['failed', 'failed', 'failed', 'published'] as $state) {
        channelPostAt($failing, $now->subHour(), $state);
    }

    foreach (['failed', 'published'] as $state) {
        channelPostAt($noisy, $now->subHour(), $state);
    }

    $stuck = Post::factory()->forAccount($failing)->create();
    Post::query()->whereKey($stuck->id)->toBase()->update(['publish_status' => PublishStatus::Publishing, 'publication_updated_at' => $now->subHour()]);
    $fresh = Post::factory()->forAccount($failing)->create();
    Post::query()->whereKey($fresh->id)->toBase()->update(['publish_status' => PublishStatus::Publishing, 'publication_updated_at' => $now->subMinutes(5)]);

    QuotaSnapshots::put(QuotaSource::YouTube, ['quotas' => [['metric' => 'youtube.googleapis.com/default', 'usage' => 9000, 'limits' => [['name' => 'defaultPerDayPerProject', 'value' => 10000]]]]]);
    QuotaSnapshots::put(QuotaSource::X, ['credits' => ['total_balance' => 4.5]]);

    $attention = BuildDashboard::execute('UTC')['attention'];

    expect(collect($attention['networks'])->pluck('platform')->all())->toBe(['linkedin-page'])
        ->and($attention['networks'][0]['network'])->toBe('linkedin')
        ->and($attention['stuck_publishing'])->toBe(1)
        ->and(collect($attention['quotas'])->pluck('source')->all())->toBe(['youtube', 'x'])
        ->and($attention['quotas'][1]['detail'])->toBe('$4.50 left');
});

test('the admin dashboard renders the summary', function () {
    $this->actingAs(admin())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Index')
            ->has('dashboard.channels')
            ->has('dashboard.publishing_days', 14)
            ->missing('dashboard.publishing'),
        );
});

test('raises no quota alert while YouTube is under the warning share and X credits are not low', function () {
    QuotaSnapshots::put(QuotaSource::YouTube, ['quotas' => [['metric' => 'youtube.googleapis.com/default', 'usage' => 7900, 'limits' => [['name' => 'defaultPerDayPerProject', 'value' => 10000]]]]]);
    QuotaSnapshots::put(QuotaSource::X, ['credits' => ['total_balance' => BuildDashboard::X_LOW_BALANCE]]);

    expect(BuildDashboard::execute('UTC')['attention']['quotas'])->toBe([]);
});

test('a network with a few failures among many posts does not need attention', function () {
    $now = CarbonImmutable::now('UTC');
    $busy = SocialAccount::factory()->create(['platform' => Platform::X]);

    foreach ([...array_fill(0, BuildDashboard::ALARMING_FAILURES, 'failed'), ...array_fill(0, 30, 'published')] as $state) {
        channelPostAt($busy, $now->subHour(), $state);
    }

    $dashboard = BuildDashboard::execute('UTC');

    expect($dashboard['last_24h']['failure_rate'])->toBeLessThan(BuildDashboard::ALARMING_FAILURE_RATE)
        ->and($dashboard['last_24h']['failed'])->toBe(BuildDashboard::ALARMING_FAILURES)
        ->and($dashboard['attention']['networks'])->toBe([]);
});

test('counts the queue, draws each day\'s publishing and leaves a failure older than 24 hours out', function () {
    $now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($now);
    $channel = SocialAccount::factory()->create(['platform' => Platform::X]);
    channelPostAt($channel, $now->subHour());
    channelPostAt($channel, $now->subHours(25), 'failed');
    Post::factory()->forAccount($channel)->scheduled()->count(2)->create();
    Post::factory()->forAccount($channel)->publishing()->create();
    Post::factory()->forAccount($channel)->pendingReview()->create();
    $retrying = Post::factory()->forAccount($channel)->publishing()->create();
    Post::query()->whereKey($retrying->id)->toBase()->update(['publish_status' => PublishStatus::Retrying->value]);

    $dashboard = BuildDashboard::execute('UTC');

    expect($dashboard['queue'])->toBe(['scheduled' => 2, 'publishing' => 1, 'retrying' => 1, 'pending_review' => 1])
        ->and($dashboard['last_24h'])->toBe(['published' => 1, 'failed' => 0, 'failure_rate' => 0.0])
        ->and(collect($dashboard['publishing_days'])->last())->toBe(['date' => $now->toDateString(), 'published' => 1, 'failed' => 0])
        ->and($dashboard['publishing_days'][BuildDashboard::CHART_DAYS - 2])->toBe(['date' => $now->subDay()->toDateString(), 'published' => 0, 'failed' => 1])
        ->and(collect($dashboard['publishing_days'])->first()['date'])->toBe($now->subDays(BuildDashboard::CHART_DAYS - 1)->toDateString());
});

test('a network failing exactly at the alarming share with enough failures needs attention', function () {
    $now = CarbonImmutable::now('UTC');
    $channel = SocialAccount::factory()->create(['platform' => Platform::X]);
    $published = (int) (BuildDashboard::ALARMING_FAILURES * (100 / BuildDashboard::ALARMING_FAILURE_RATE - 1));

    foreach ([...array_fill(0, BuildDashboard::ALARMING_FAILURES, 'failed'), ...array_fill(0, $published, 'published')] as $state) {
        channelPostAt($channel, $now->subHour(), $state);
    }

    $dashboard = BuildDashboard::execute('UTC');

    expect($dashboard['last_24h']['failure_rate'])->toBe(BuildDashboard::ALARMING_FAILURE_RATE)
        ->and(collect($dashboard['attention']['networks'])->pluck('platform')->all())->toBe(['x']);
});
