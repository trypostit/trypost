<?php

declare(strict_types=1);

use App\Actions\SocialAccount\BuildChannelDistribution;
use App\Actions\SocialAccount\BuildNetworkHealth;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('counts channels by status and failure rates per network', function () {
    $now = CarbonImmutable::now('UTC');
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'status' => Status::TokenExpired]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'status' => Status::Disconnected]);

    channelPostAt($x, $now->subHour(), 'published');
    channelPostAt($x, $now->subHours(2), 'failed');
    channelPostAt($x, $now->subDays(3), 'failed');
    channelPostAt($x, $now->subDays(9), 'published');

    $x = collect(BuildNetworkHealth::execute())->firstWhere('platform', 'x');

    expect($x['channels'])->toBe(['connected' => 1, 'token_expired' => 1, 'disconnected' => 1])
        ->and($x['last_24h'])->toMatchArray(['published' => 1, 'failed' => 1, 'failure_rate' => 50.0])
        ->and($x['last_7d'])->toMatchArray(['published' => 1, 'failed' => 2])
        ->and($x['last_failure_at'])->toBe($now->subHours(2)->toIso8601String());
});

test('lists only the enabled networks, worst failure rate first', function () {
    config()->set('trypost.platforms.tiktok.enabled', false);
    $now = CarbonImmutable::now('UTC');
    channelPostAt(SocialAccount::factory()->create(['platform' => Platform::LinkedIn]), $now->subHour(), 'failed');
    channelPostAt(SocialAccount::factory()->create(['platform' => Platform::X]), $now->subHour(), 'published');

    $health = collect(BuildNetworkHealth::execute());

    expect($health->pluck('platform'))->not->toContain('tiktok')
        ->and($health->first()['platform'])->toBe('linkedin');
});

test('the admin networks page renders the health of every network', function () {
    $this->actingAs(admin())
        ->get(route('admin.networks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/networks/Index')
            ->has('networks'),
        );
});

test('reads the networks by channels, by accounts and by the first network each account connected', function () {
    [$big, $small] = [workspaceOwner(), workspaceOwner()];
    $bigWorkspace = $big->currentWorkspace;
    $smallWorkspace = $small->currentWorkspace;

    SocialAccount::factory()->create(['workspace_id' => $bigWorkspace->id, 'platform' => Platform::X, 'created_at' => now()->subDays(9)]);
    SocialAccount::factory()->count(2)->create(['workspace_id' => $bigWorkspace->id, 'platform' => Platform::Instagram]);
    SocialAccount::factory()->create(['workspace_id' => $bigWorkspace->id, 'platform' => Platform::InstagramFacebook]);
    SocialAccount::factory()->create(['workspace_id' => $smallWorkspace->id, 'platform' => Platform::Instagram, 'status' => Status::TokenExpired, 'created_at' => now()->subDays(9)]);
    SocialAccount::factory()->create(['workspace_id' => $smallWorkspace->id, 'platform' => Platform::Instagram]);

    $distribution = BuildChannelDistribution::execute();

    expect($distribution['totals'])->toBe(['channels' => 5, 'accounts' => 2, 'first' => 2])
        ->and(collect($distribution['channels'])->pluck('count', 'key')->all())->toBe(['instagram' => 4, 'x' => 1])
        ->and($distribution['accounts'][0])->toMatchArray(['key' => 'instagram', 'count' => 2, 'share' => 100.0, 'per_account' => 2.0])
        ->and($distribution['accounts'][1])->toMatchArray(['key' => 'x', 'count' => 1, 'share' => 50.0])
        ->and(collect($distribution['first'])->pluck('count', 'key')->sortKeys()->all())->toBe(['instagram' => 1, 'x' => 1]);
});
