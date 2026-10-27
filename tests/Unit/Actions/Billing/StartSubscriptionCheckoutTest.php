<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\User\Persona;
use App\Enums\User\PreviousSoftware;
use App\Enums\User\ReferralSource;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;
use Mockery\MockInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

function fakeStripeCustomerClient(): ClientInterface
{
    $client = new class implements ClientInterface
    {
        /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            $id = str_contains($absUrl, 'cus_existing') ? 'cus_existing' : 'cus_new';

            return [json_encode(['id' => $id, 'object' => 'customer', 'email' => data_get($params, 'email')]), 200, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

function checkoutCustomerBuilder(): SubscriptionBuilder
{
    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->andReturnSelf();
    $builder->shouldReceive('trialDays')->andReturnSelf();
    $builder->shouldReceive('checkout')->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    return $builder;
}

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('redirect keeps the attribution metadata on the stripe customer, not on the subscription', function () {

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
        'previous_software' => PreviousSoftware::Metricool,
        'referral_source' => ReferralSource::ProductHunt,
    ]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->with([])->andReturnSelf();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('withCoupon')->never();
    $builder->shouldReceive('allowPromotionCodes')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->with([
            'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'trypost_interval' => 'monthly',
            ],
        ])
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();

    expect($account->stripeMetadata())->toBe([
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
        'previous_software' => 'metricool',
        'referral_source' => 'product_hunt',
    ]);
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

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $account->refresh();

    $cancelUrl = route('app.welcome');

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->with([])->andReturnSelf();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();

    expect($account->stripeMetadata())->toBe([]);
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', $cancelUrl);
});

test('redirect cuts an oversized click id to the stripe metadata limit', function () {

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create([
        'account_id' => $account->id,
        'fbclid' => str_repeat('a', 900),
        'referral_source' => null,
    ]);
    $account->refresh();

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();

    expect($account->stripeMetadata())->toBe(['fbclid' => str_repeat('a', 500)]);
    $accountMock->shouldReceive('newSubscription')->once()->andReturn(checkoutCustomerBuilder());

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome'));
});

test('checkout session carries the plan and interval of a yearly price', function () {

    $plan = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_workspaces_monthly',
        'stripe_yearly_price_id' => 'price_workspaces_yearly',
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->withArgs(fn (array $options): bool => data_get($options, 'metadata') === [
            'trypost_plan_slug' => 'workspaces',
            'trypost_interval' => 'yearly',
        ])
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_workspaces_yearly',
        route('app.welcome.plan'),
        $plan,
    );
});

test('redirect clears a leftover plan_id before opening checkout', function () {

    $workspaces = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $account = Account::factory()->create(['plan_id' => $workspaces->id]);
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('checkout')
        ->once()
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome.software'));

    expect($account->fresh()->plan_id)->toBeNull();
});

test('redirect creates a new stripe customer with the owner email, the owner name and the metadata', function () {
    config([
        'cashier.secret' => 'sk_test_fake',
    ]);
    $client = fakeStripeCustomerClient();

    $account = Account::factory()->create(['name' => "Ada's Account"]);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'utm_source' => 'google',
        'previous_software' => PreviousSoftware::Metricool,
        'referral_source' => null,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $account->refresh();

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn(checkoutCustomerBuilder());

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome.software'));

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['method'])->toBe('post')
        ->and($client->requests[0]['url'])->toEndWith('/v1/customers')
        ->and($client->requests[0]['params'])->toMatchArray([
            'email' => 'ada@example.test',
            'name' => 'Ada Lovelace',
            'metadata' => ['utm_source' => 'google', 'previous_software' => 'metricool'],
        ])
        ->and($account->fresh()->stripe_id)->toBe('cus_new');
});

test('redirect updates an existing stripe customer with the current details and metadata', function () {
    config([
        'cashier.secret' => 'sk_test_fake',
    ]);
    $client = fakeStripeCustomerClient();

    $account = Account::factory()->create(['name' => "Ada's Account", 'stripe_id' => 'cus_existing']);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'name' => 'New Owner',
        'email' => 'new-owner@example.test',
        'persona' => Persona::Agency,
        'referral_source' => null,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $account->refresh();

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn(checkoutCustomerBuilder());

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_monthly_test', route('app.welcome.software'));

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['method'])->toBe('post')
        ->and($client->requests[0]['url'])->toEndWith('/v1/customers/cus_existing')
        ->and($client->requests[0]['params'])->toMatchArray([
            'email' => 'new-owner@example.test',
            'name' => 'New Owner',
        ])
        ->and($client->requests[0]['params']['metadata'])->toMatchArray(['persona' => 'agency', 'referral_source' => ''])
        ->and($account->fresh()->stripe_id)->toBe('cus_existing');
});

test('stores the offer id and slug in subscription and checkout metadata', function () {

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly']);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $offer = Offer::factory()->create(['stripe_coupon_id' => 'BLACK_FRIDAY_50']);
    $account->offers()->attach($offer, ['claimed_at' => now()]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withCoupon')->once()->with('BLACK_FRIDAY_50')->andReturnUsing(function () use ($builder): SubscriptionBuilder {
        $builder->couponId = 'BLACK_FRIDAY_50';

        return $builder;
    });
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with(['trypost_offer_id' => $offer->id, 'trypost_offer_slug' => $offer->slug])
        ->andReturnSelf();
    $builder->shouldReceive('checkout')
        ->once()
        ->withArgs(fn (array $options): bool => data_get($options, 'metadata.trypost_offer_slug') === $offer->slug)
        ->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_socials_monthly',
        route('app.welcome.plan'),
        $plan,
        $offer,
    );
});

test('redirect uses the offer it is given and never looks one up', function () {

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly']);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $offer = Offer::factory()->create(['stripe_coupon_id' => 'BLACK_FRIDAY_50']);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withCoupon')->once()->with('BLACK_FRIDAY_50')->andReturnUsing(function () use ($builder): SubscriptionBuilder {
        $builder->couponId = 'BLACK_FRIDAY_50';

        return $builder;
    });
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with(['trypost_offer_id' => $offer->id, 'trypost_offer_slug' => $offer->slug])
        ->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);
    $accountMock->shouldReceive('currentOffer')->never();

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_socials_monthly',
        route('app.welcome.plan'),
        $plan,
        $offer,
    );
});

test('redirect skips the offer on a yearly price', function () {

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_socials_monthly',
        'stripe_yearly_price_id' => 'price_socials_yearly',
    ]);

    $account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('withMetadata')->once()->with([])->andReturnSelf();
    $builder->shouldReceive('withCoupon')->never();
    $builder->shouldReceive('trialDays')->never();
    $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect(
        $accountMock,
        'price_socials_yearly',
        route('app.welcome.plan'),
        $plan,
        Offer::factory()->create(),
    );
});

test('redirect gives the trial of the offer it is given', function () {
    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly']);

    $account = Account::factory()->create();
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $offer = Offer::factory()->freeTrial()->create();
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('trialDays')->once()->with(8)->andReturnSelf();
    $builder->shouldReceive('withCoupon')->never();
    $builder->shouldReceive('withMetadata')
        ->once()
        ->with(['trypost_offer_id' => $offer->id, 'trypost_offer_slug' => 'free-trial-7-days'])
        ->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_socials_monthly', route('app.welcome.plan'), $plan, $offer);
});

test('redirect keeps the days left of a generic trial and its plan', function () {
    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly']);
    $trialEndsAt = now()->addDays(4)->startOfSecond();

    $account = Account::factory()->create(['plan_id' => $plan->id, 'trial_ends_at' => $trialEndsAt]);
    User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
    $account->refresh();

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('trialUntil')->once()->withArgs(fn ($until): bool => $until->eq($trialEndsAt))->andReturnSelf();
    $builder->shouldReceive('withMetadata')->once()->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn((object) ['url' => 'https://checkout.stripe.test/session']);

    /** @var Account&MockInterface $accountMock */
    $accountMock = Mockery::mock($account)->makePartial();
    $accountMock->shouldReceive('syncOrCreateStripeCustomer')->once()->andReturnNull();
    $accountMock->shouldReceive('newSubscription')->once()->andReturn($builder);

    app(StartSubscriptionCheckout::class)->redirect($accountMock, 'price_socials_monthly', route('app.billing.index'), $plan);

    expect($account->fresh()->plan_id)->toBe($plan->id);
});
