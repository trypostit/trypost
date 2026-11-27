<?php

declare(strict_types=1);

use App\Actions\Admin\BuildChannelDistribution;
use App\Actions\Admin\BuildNetworkHealth;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;

test('counts channels by status and failure rates per network', function () {
    $now = CarbonImmutable::now('UTC');
    $x = SocialAccount::factory()->create(['platform' => Platform::X]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'status' => Status::TokenExpired]);
    SocialAccount::factory()->create(['platform' => Platform::X, 'status' => Status::Disconnected]);

    adminPostSentAt($x, $now->subHour(), 'published');
    adminPostSentAt($x, $now->subHours(2), 'failed');
    adminPostSentAt($x, $now->subDays(3), 'failed');
    adminPostSentAt($x, $now->subDays(9), 'published');

    $x = collect(BuildNetworkHealth::execute())->firstWhere('platform', 'x');

    expect($x['channels'])->toBe(['connected' => 1, 'token_expired' => 1, 'disconnected' => 1])
        ->and($x['last_24h'])->toMatchArray(['published' => 1, 'failed' => 1, 'failure_rate' => 50.0])
        ->and($x['last_7d'])->toMatchArray(['published' => 1, 'failed' => 2])
        ->and($x['last_failure_at'])->toBe($now->subHours(2)->toIso8601String());
});

test('lists only the enabled networks, worst failure rate first', function () {
    config()->set('trypost.platforms.tiktok.enabled', false);
    $now = CarbonImmutable::now('UTC');
    adminPostSentAt(SocialAccount::factory()->create(['platform' => Platform::LinkedIn]), $now->subHour(), 'failed');
    adminPostSentAt(SocialAccount::factory()->create(['platform' => Platform::X]), $now->subHour(), 'published');

    $health = collect(BuildNetworkHealth::execute());

    expect($health->pluck('platform'))->not->toContain('tiktok')
        ->and($health->first()['platform'])->toBe('linkedin');
});

test('the admin networks page renders the health of every network', function () {
    $this->actingAs(backofficeAdmin())
        ->get(route('admin.networks.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/networks/Index')
            ->has('networks'),
        );
});

test('reads the networks by channels, by accounts and by the first network each account connected', function () {
    [$big, $small] = [User::factory()->create(), User::factory()->create()];
    $bigWorkspace = Workspace::factory()->create(['user_id' => $big->id, 'account_id' => $big->account_id]);
    $smallWorkspace = Workspace::factory()->create(['user_id' => $small->id, 'account_id' => $small->account_id]);

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
