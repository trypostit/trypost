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
        $post = Post::factory()->forAccount($channel)->{$state}()->create();
        Post::query()->whereKey($post->id)->toBase()->update(['publication_updated_at' => $now->subHour()]);
    }

    $dashboard = BuildDashboard::execute('UTC');

    expect($dashboard)->not->toHaveKeys(['users', 'accounts'])
        ->and($dashboard['last_24h'])->toMatchArray(['published' => 2, 'failed' => 1])
        ->and($dashboard['channels'])->toMatchArray(['to_reconnect' => 1])
        ->and($dashboard['publishing']['days'])->toHaveCount(14);
});

test('flags a network failing often, posts stuck publishing and quotas running out', function () {
    $now = CarbonImmutable::now('UTC');
    $failing = SocialAccount::factory()->create(['platform' => Platform::LinkedInPage]);
    $noisy = SocialAccount::factory()->create(['platform' => Platform::GoogleBusiness]);

    foreach (['failed', 'failed', 'failed', 'published'] as $state) {
        $post = Post::factory()->forAccount($failing)->{$state}()->create();
        Post::query()->whereKey($post->id)->toBase()->update(['publication_updated_at' => $now->subHour()]);
    }

    foreach (['failed', 'published'] as $state) {
        $post = Post::factory()->forAccount($noisy)->{$state}()->create();
        Post::query()->whereKey($post->id)->toBase()->update(['publication_updated_at' => $now->subHour()]);
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
    $this->actingAs(backofficeAdmin())
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/Index')
            ->has('dashboard.channels')
            ->has('dashboard.publishing.days', 14),
        );
});

test('raises no quota alert while YouTube is under the warning share and X credits are not low', function () {
    QuotaSnapshots::put(QuotaSource::YouTube, ['quotas' => [['metric' => 'youtube.googleapis.com/default', 'usage' => 7900, 'limits' => [['name' => 'defaultPerDayPerProject', 'value' => 10000]]]]]);
    QuotaSnapshots::put(QuotaSource::X, ['credits' => ['total_balance' => BuildDashboard::X_LOW_BALANCE]]);

    expect(BuildDashboard::execute('UTC')['attention']['quotas'])->toBe([]);
});
