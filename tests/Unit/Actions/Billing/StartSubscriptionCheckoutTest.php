<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Models\Account;
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
        'cashier.first_month_coupon_id' => '',
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
        'persona' => Persona::Agency,
        'goals' => ['grow_audience', 'save_time'],
        'referral_source' => ReferralSource::ProductHunt,
    ]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('quantity')->once()->with(1)->andReturnSelf();
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with([
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'utm_campaign' => 'launch',
            'utm_term' => 'social scheduler',
            'utm_content' => 'headline-b',
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
        'cashier.first_month_coupon_id' => '',
        'cashier.allow_promotion_codes' => false,
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('quantity')->once()->andReturnSelf();
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
