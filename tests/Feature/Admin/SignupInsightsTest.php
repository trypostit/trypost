<?php

declare(strict_types=1);

use App\Actions\Admin\BuildSignupInsights;
use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;

beforeEach(function () {
    $this->now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($this->now->subDays(30));
    $this->admin = backofficeAdmin();
    $this->travelTo($this->now);
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/** An owner who signed up today with the Welcome answers given. */
function adminSignup(array $answers = []): User
{
    return User::factory()->create(['persona' => null, 'goals' => null, 'previous_software' => null, ...$answers]);
}

test('follows the owners who signed up through Welcome, a subscription, a channel and a post', function () {
    $paid = adminSignup(['persona' => 'agency', 'goals' => ['save_time'], 'previous_software' => 'buffer', 'google_id' => 'g-1', 'password' => null]);
    adminBackdateSubscription(subscribeAccount($paid->account), $paid->created_at->addMinutes(4));
    fakeStripeReads(['price_123' => [1900, 'month']]);
    $workspace = Workspace::factory()->create(['user_id' => $paid->id, 'account_id' => $paid->account_id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    Post::factory()->forAccount($channel)->published()->create(['user_id' => $paid->id]);

    adminSignup(['persona' => 'agency', 'goals' => ['save_time', 'use_mcp'], 'previous_software' => 'none']);
    adminSignup(['persona' => 'creator']);

    $member = adminSignup(['persona' => 'creator']);
    $member->account->forceFill(['owner_id' => $paid->id])->save();
    $member->forceFill(['account_id' => $paid->account_id])->save();

    $insights = BuildSignupInsights::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'));
    $funnel = collect($insights['funnel'])->pluck('count', 'key');
    $personas = collect($insights['personas'])->keyBy('value');

    expect($insights['totals'])->toMatchArray(['signups' => 3, 'subscribed' => 1, 'subscribed_rate' => 33.3, 'new_mrr_cents' => 1900, 'minutes_to_subscribe' => 4.0])
        ->and($funnel->all())->toMatchArray(['signed_up' => 3, 'persona' => 3, 'goals' => 2, 'software' => 2, 'subscribed' => 1, 'connected' => 1, 'published' => 1])
        ->and($personas['agency'])->toMatchArray(['count' => 2, 'subscribed' => 1, 'subscribed_rate' => 50.0])
        ->and(collect($insights['goals'])->pluck('count', 'value')->all())->toMatchArray(['save_time' => 2, 'use_mcp' => 1])
        ->and(collect($insights['software'])->firstWhere('value', 'none')['label'])->toBe('None yet')
        ->and(collect($insights['signup_methods'])->pluck('count', 'value')->all())->toEqual(['google' => 1, 'email' => 2]);
});

test('counts a signup on the day it happened in the viewer\'s zone', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    $today = $this->now->startOfDay();
    $this->travelTo($today->addHour());
    $owner = adminSignup();
    adminBackdateSubscription(subscribeAccount($owner->account), $owner->created_at);
    $this->travelTo($this->now);

    $saoPaulo = BuildSignupInsights::execute(DateRange::fromDates($today->subDay()->toDateString(), $today->toDateString(), 'America/Sao_Paulo'));
    $utc = BuildSignupInsights::execute(DateRange::fromDates($today->subDay()->toDateString(), $today->toDateString(), 'UTC'));

    expect($saoPaulo['signups_by_day'])->toBe([1, 0])
        ->and($saoPaulo['subscribed_by_day'])->toBe([1, 0])
        ->and($utc['signups_by_day'])->toBe([0, 1])
        ->and($utc['subscribed_by_day'])->toBe([0, 1]);
});

test('the insights page shows the signup funnel', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.billing.insights.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/billing/insights/Index')->has('insights.funnel', 7));
});

test('a checkout that never billed does not count as subscribed', function (string $status) {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    $owner = adminSignup();
    subscribeAccount($owner->account, ['stripe_status' => $status]);

    $insights = BuildSignupInsights::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'));

    expect($insights['totals'])->toMatchArray(['signups' => 1, 'subscribed' => 0, 'new_mrr_cents' => 0, 'minutes_to_subscribe' => null])
        ->and(collect($insights['funnel'])->firstWhere('key', 'subscribed')['count'])->toBe(0);
})->with(['incomplete', 'incomplete_expired']);

test('an owner\'s first subscription decides when they subscribed and what it brings', function () {
    fakeStripeReads(['price_123' => [1900, 'month'], 'price_yearly' => [19000, 'year']]);
    $owner = adminSignup();
    $later = subscribeAccount($owner->account, ['stripe_price' => 'price_yearly']);
    $first = subscribeAccount($owner->account, ['stripe_status' => 'canceled', 'ends_at' => $this->now->subHour()]);
    adminBackdateSubscription($first, $owner->created_at->addMinutes(10));
    adminBackdateSubscription($later, $owner->created_at->addMinutes(90));

    $totals = BuildSignupInsights::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'))['totals'];

    expect($totals)->toMatchArray(['subscribed' => 1, 'minutes_to_subscribe' => 10.0, 'new_mrr_cents' => 1900]);
});

test('the period before counts its own signups', function () {
    $this->travelTo($this->now->subDays(9));
    adminSignup();
    adminSignup();
    $this->travelTo($this->now);
    adminSignup();

    $insights = BuildSignupInsights::execute(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC'));

    expect($insights['totals']['signups'])->toBe(1)
        ->and($insights['previous'])->toMatchArray(['signups' => 2, 'subscribed' => 0, 'subscribed_rate' => 0.0]);
});
