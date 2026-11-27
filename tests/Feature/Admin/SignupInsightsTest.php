<?php

declare(strict_types=1);

use App\Actions\Admin\BuildSignupInsights;
use App\Enums\SocialAccount\Platform;
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
    $this->travelTo($this->now->subDays(30));
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test']);
    $this->travelTo($this->now);
});

/** An owner who signed up today with the Welcome answers given. */
function signedUp(array $answers = []): User
{
    return User::factory()->create(['persona' => null, 'goals' => null, 'previous_software' => null, ...$answers]);
}

function subscribe(User $user, int $minutesLater = 0): void
{
    $subscription = $user->account->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_'.str()->random(8), 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1]);
    $subscription->forceFill(['created_at' => $user->created_at->addMinutes($minutesLater)])->save();
}

test('follows the owners who signed up through Welcome, a subscription, a channel and a post', function () {
    $paid = signedUp(['persona' => 'agency', 'goals' => ['save_time'], 'previous_software' => 'buffer', 'google_id' => 'g-1']);
    subscribe($paid, 4);
    Cache::forever('admin:stripe-price-monthly:price_x', 1900);
    $workspace = Workspace::factory()->create(['user_id' => $paid->id, 'account_id' => $paid->account_id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    Post::factory()->forAccount($channel)->published()->create(['user_id' => $paid->id]);

    signedUp(['persona' => 'agency', 'goals' => ['save_time', 'use_mcp'], 'previous_software' => 'none']);
    signedUp(['persona' => 'creator']);

    $member = signedUp(['persona' => 'creator']);
    $member->account->forceFill(['owner_id' => $paid->id])->save();
    $member->forceFill(['account_id' => $paid->account_id])->save();

    $insights = BuildSignupInsights::execute($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');
    $funnel = collect($insights['funnel'])->pluck('count', 'key');
    $personas = collect($insights['personas'])->keyBy('value');

    expect($insights['totals'])->toMatchArray(['signups' => 3, 'subscribed' => 1, 'subscribed_rate' => 33.3, 'new_mrr_cents' => 1900, 'minutes_to_subscribe' => 4.0])
        ->and($funnel->all())->toMatchArray(['signed_up' => 3, 'persona' => 3, 'goals' => 2, 'software' => 2, 'subscribed' => 1, 'connected' => 1, 'published' => 1])
        ->and($personas['agency'])->toMatchArray(['count' => 2, 'subscribed' => 1, 'subscribed_rate' => 50.0])
        ->and(collect($insights['goals'])->pluck('count', 'value')->all())->toMatchArray(['save_time' => 2, 'use_mcp' => 1])
        ->and(collect($insights['software'])->firstWhere('value', 'none')['label'])->toBe('None yet')
        ->and(collect($insights['signup_methods'])->pluck('count', 'value')->all())->toMatchArray(['google' => 1, 'email' => 2]);
});

test('the insights page shows the signup funnel', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.billing.insights.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/insights/Index')->has('insights.funnel', 7));
});
