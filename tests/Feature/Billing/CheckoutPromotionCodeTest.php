<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\User;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config(['cashier.secret' => 'sk_test_fake']);

    $this->socials = Plan::where('slug', Slug::Socials)->firstOrFail();
    $this->socials->update(['stripe_monthly_price_id' => 'price_socials_monthly']);

    $this->account = Account::factory()->create();
    $this->account->update(['owner_id' => User::factory()->create(['account_id' => $this->account->id])->id]);

    $this->stripe = new class implements ClientInterface
    {
        /** @var array<string, mixed> */
        public array $session = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            if (str_contains($absUrl, '/v1/checkout/sessions')) {
                $this->session = $params;

                return [json_encode(['id' => 'cs_fake', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/session']), 200, []];
            }

            if (str_contains($absUrl, '/v1/coupons/')) {
                return [json_encode(['id' => 'BF26_TEST', 'object' => 'coupon', 'percent_off' => 50, 'duration' => 'repeating', 'duration_in_months' => 3]), 200, []];
            }

            return [json_encode(['id' => 'cus_fake', 'object' => 'customer']), 200, []];
        }
    };

    ApiRequestor::setHttpClient($this->stripe);
});

test('a checkout without an offer charges the full price and takes a promotion code', function () {
    app(StartSubscriptionCheckout::class)->redirect($this->account, 'price_socials_monthly', route('app.welcome.plan'), $this->socials);

    expect(data_get($this->stripe->session, 'allow_promotion_codes'))->toBe('true')
        ->and(data_get($this->stripe->session, 'discounts'))->toBeNull()
        ->and(data_get($this->stripe->session, 'subscription_data.trial_end'))->toBeNull();
});

test('a coupon offer sends its coupon and no promotion code field, which Stripe rejects together', function () {
    $offer = Offer::factory()->create(['stripe_coupon_id' => 'BF26_TEST']);

    app(StartSubscriptionCheckout::class)->redirect($this->account, 'price_socials_monthly', route('app.welcome.plan'), $this->socials, $offer);

    expect(data_get($this->stripe->session, 'discounts.0.coupon'))->toBe('BF26_TEST')
        ->and(data_get($this->stripe->session, 'allow_promotion_codes'))->toBeNull();
});

test('a trial offer that does not allow promotion codes sends the trial without the field', function () {
    $offer = Offer::factory()->trial(8)->create();

    app(StartSubscriptionCheckout::class)->redirect($this->account, 'price_socials_monthly', route('app.welcome.plan'), $this->socials, $offer);

    expect(data_get($this->stripe->session, 'subscription_data.trial_end'))->not->toBeNull()
        ->and(data_get($this->stripe->session, 'allow_promotion_codes'))->toBeNull();
});
