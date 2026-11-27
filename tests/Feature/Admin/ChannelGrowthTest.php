<?php

declare(strict_types=1);

use App\Actions\Admin\BuildChannelGrowth;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('counts the channels connected per UTC day and network', function () {
    $today = CarbonImmutable::now('UTC')->startOfDay();

    SocialAccount::factory()->create(['platform' => Platform::LinkedIn, 'created_at' => $today->addHour()]);
    SocialAccount::factory()->create(['platform' => Platform::LinkedInPage, 'created_at' => $today->addHours(2)]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'created_at' => $today->subDay()->addHour()]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'created_at' => $today->subDays(10)]);

    $growth = BuildChannelGrowth::execute($today->subDays(6)->toDateString(), $today->toDateString(), 'UTC');

    expect($growth['days'])->toHaveCount(7)
        ->and($growth['new_in_range'])->toBe(3)
        ->and($growth['by_network']['linkedin'][6])->toBe(2)
        ->and($growth['by_network']['x'][5])->toBe(1)
        ->and($growth['connected_now'])->toBe(4)
        ->and($growth['total_by_day'])->toBe([1, 1, 1, 1, 1, 2, 4]);
});

test('the networks page takes the Insights range, 30 days by default', function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);

    $this->actingAs(User::factory()->create(['email' => 'admin@trypost.test']))
        ->get(route('admin.networks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/networks/Index')
            ->where('filters.range', '30d')
            ->has('growth.days', 30)
            ->has('networks'),
        );
});
