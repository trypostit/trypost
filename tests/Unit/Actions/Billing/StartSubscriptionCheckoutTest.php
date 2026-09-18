<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

test('redirect applies checkout configuration and attribution metadata before opening the stripe session', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids' => [
            'socials' => '',
            'workspaces' => '',
        ],
        'cashier.allow_promotion_codes' => false,
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create([
        'account_id' => $account->id,
        'utm_source' => 'google',
        'utm_medium' => 'cpc',
        'utm_campaign' => 'launch',
        'utm_term' => 'social scheduler',
        'utm_content' => 'headline-b',
        'gclid' => 'Cj0KCQgclid',
        'fbclid' => 'IwAR0fbclid',
        'li_fat_id' => '9f2li-fat-id',
        'ttclid' => 'E.C.Pttclid',
        'rdt_cid' => 'rdt-cid-value',
        'epik' => 'dj0yepik',
        'persona' => Persona::Agency,
        'goals' => ['grow_audience', 'save_time'],
        'referral_source' => ReferralSource::ProductHunt,
    ]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'launch',
            'utm_term' => 'social scheduler',
            'utm_content' => 'headline-b',
            'gclid' => 'Cj0KCQgclid',
            'fbclid' => 'IwAR0fbclid',
            'li_fat_id' => '9f2li-fat-id',
            'ttclid' => 'E.C.Pttclid',
            'rdt_cid' => 'rdt-cid-value',
            'epik' => 'dj0yepik',
            'persona' => 'agency',
            'goals' => 'grow_audience,save_time',
            'referral_source' => 'product_hunt',
        ])
        ->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->with(8)->andReturnSelf();
    $builder->shouldReceive('withCoupon')->never();
    $builder->shouldReceive('allowPromotionCodes')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->with([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ])
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')
        ->once()
        ->with(Account::SUBSCRIPTION_NAME, 'price_monthly_test')
        ->andReturn($builder);

    $response = app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_monthly_test',
        $cancelUrl,
    );

    expect($response->headers->get('Location'))->toBe('https://checkout.stripe.test/session')
        ->and($response->getStatusCode())->toBe(Response::HTTP_FOUND);
});

test('redirect sends no metadata for an account whose owner left every field empty', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids' => [
            'socials' => '',
            'workspaces' => '',
        ],
        'cashier.allow_promotion_codes' => false,
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->with([])->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->andReturnSelf();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', $cancelUrl);
});

test('redirect cuts an oversized click id to the stripe metadata limit', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids' => [
            'socials' => '',
            'workspaces' => '',
        ],
        'cashier.allow_promotion_codes' => false,
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create([
        'account_id' => $account->id,
        'fbclid' => str_repeat('a', 900),
    ]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with(['fbclid' => str_repeat('a', 500)])
        ->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->andReturnSelf();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome'));
});

test('redirect applies the plan first-month coupon on a monthly price', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids.socials' => 'SOCIALS_18USD',
        'cashier.allow_promotion_codes' => false,
    ]);

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly']);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('withCoupon')->once()->with('SOCIALS_18USD')->andReturnSelf();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_socials_monthly',
        route('app.welcome.plan'),
        $plan,
    );
});

test('redirect skips the first-month coupon on a yearly price', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids.workspaces' => 'WORKSPACES_88USD',
        'cashier.allow_promotion_codes' => false,
    ]);

    $plan = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_workspaces_monthly',
        'stripe_yearly_price_id' => 'price_workspaces_yearly',
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('withCoupon')->never();
    $builder->shouldReceive('trialDays')->once()->with(8)->andReturnSelf();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_workspaces_yearly',
        route('app.welcome.plan'),
        $plan,
    );
});

test('redirect clears a leftover plan_id before opening checkout', function () {
    config([
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.first_month_coupon_ids' => [
            'socials' => '',
            'workspaces' => '',
        ],
        'cashier.allow_promotion_codes' => false,
    ]);

    $workspaces = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $account = Account::factory()->create(['plan_id' => $workspaces->id]);
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('trialDays')->once()->andReturnSelf();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('createOrGetStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome.plan'));

    expect($account->fresh()->plan_id)->toBeNull();
});
