<?php

declare(strict_types=1);

use App\Actions\Admin\UserProfile\BuildUserHealth;
use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\BuildUsageInsights;
use App\Enums\Account\LifecycleStage;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    $this->now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($this->now);
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test']);
});

/** An owner with a workspace and a channel of the given status. */
function accountWithChannel(string $name, Status $status = Status::Connected): array
{
    $owner = User::factory()->create(['name' => $name]);
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X, 'status' => $status]);

    return [$owner, $channel];
}

function sentAt(SocialAccount $channel, CarbonImmutable $at, string $state = 'published', array $attributes = []): void
{
    $post = Post::factory()->forAccount($channel)->{$state}()->create($attributes);
    Post::query()->whereKey($post->id)->toBase()->update(['publication_updated_at' => $at, 'published_at' => $at]);
}

test('usage ranks the accounts by posts, channels, automation and failures, against the period before', function () {
    [$busy, $busyChannel] = accountWithChannel('Busy');
    [$quiet, $quietChannel] = accountWithChannel('Quiet');

    foreach (range(1, 3) as $index) {
        sentAt($busyChannel, $this->now->subHours($index), 'published', ['created_via' => 'api']);
    }

    sentAt($quietChannel, $this->now->subHour());
    sentAt($quietChannel, $this->now->subHour(), 'failed');
    sentAt($quietChannel, $this->now->subDays(10));

    $usage = BuildUsageInsights::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($usage['totals'])->toMatchArray(['active_accounts' => 2, 'posts' => 4, 'posts_per_active' => 2.0, 'channels_per_account' => 1.0])
        ->and($usage['previous'])->toMatchArray(['active_accounts' => 1, 'posts' => 1])
        ->and($usage['top_accounts'][0])->toMatchArray(['name' => 'Busy', 'posts' => 3, 'automated' => 3, 'channels' => 1, 'failed' => 0])
        ->and(collect($usage['top_accounts'])->firstWhere('name', 'Quiet'))->toMatchArray(['posts' => 1, 'failed' => 1])
        ->and(collect($usage['lifecycle']['stages'])->pluck('key')->all())
        ->toBe(collect(BuildUsageInsights::CUSTOMER_STAGES)->map->value->all());
});

test('churn risk flags the paying accounts that stopped, cancel, owe or lost their channels', function () {
    Cache::forever('admin:stripe-price-monthly:price_x', 1900);

    [$fine, $fineChannel] = accountWithChannel('Fine');
    [$leaving, $leavingChannel] = accountWithChannel('Leaving', Status::TokenExpired);
    sentAt($leavingChannel, $this->now->subDays(30));
    foreach ([$fine, $leaving] as $owner) {
        $subscription = $owner->account->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.str()->random(8), 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1]);
        $subscription->forceFill(['created_at' => $this->now->subDays(30)])->save();
    }
    sentAt($fineChannel, $this->now->subHour());
    $leaving->account->subscription()->update(['ends_at' => $this->now->addDays(5)]);

    $churn = BuildChurnRisk::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect($churn)->toMatchArray(['base' => 2, 'active' => 1, 'at_risk' => 1, 'mrr_at_risk_cents' => 1900])
        ->and($churn['accounts'][0]['name'])->toBe('Leaving')
        ->and($churn['accounts'][0]['signals'])->toEqualCanonicalizing(['dormant', 'cancelling', 'channels_broken'])
        ->and($churn['accounts'][0]['stage'])->toBe(LifecycleStage::Dormant->value);
});

test('the people insights page shows usage and churn risk', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.insights.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Insights')
            ->has('usage.top_accounts')
            ->has('churn.accounts'),
        );
});

test('a user profile reads the account health and churn risk the way the insights do', function () {
    Cache::forever('admin:stripe-price-monthly:price_x', 1900);

    [$owner, $channel] = accountWithChannel('Leaving', Status::TokenExpired);
    $owner->account->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.str()->random(8), 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1, 'ends_at' => $this->now->addDays(5)])
        ->forceFill(['created_at' => $this->now->subDays(60)])->save();
    sentAt($channel, $this->now->subDays(40));
    sentAt($channel, $this->now->subDays(35), 'published', ['created_via' => 'api']);
    sentAt($channel, $this->now->subDays(25));
    sentAt($channel, $this->now->subDays(24), 'failed');

    $health = BuildUserHealth::execute($owner);

    expect($health['stage'])->toBe(LifecycleStage::Dormant->value)
        ->and($health['posts'])->toMatchArray(['sent' => 1, 'previous' => 2, 'automated' => 0, 'failed' => 1, 'failure_rate' => 50.0])
        ->and($health['channels'])->toBe(['total' => 1, 'broken' => 1])
        ->and($health['risk'])->toMatchArray(['level' => 'high', 'mrr_cents' => 1900])
        ->and($health['risk']['signals'])->toEqualCanonicalizing(['dormant', 'cancelling', 'channels_broken']);
});

test('a user profile shows no churn risk for an account that pays nothing', function () {
    [$owner] = accountWithChannel('Someone');

    expect(BuildUserHealth::execute($owner)['risk'])->toBeNull();
});
