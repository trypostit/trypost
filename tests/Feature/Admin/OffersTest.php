<?php

declare(strict_types=1);

use App\Enums\Activity\LogName;
use App\Models\Activity;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config()->set('trypost.admin.emails', ['admin@trypost.test']);
    config()->set('cashier.secret', 'sk_test_fake');
    $this->admin = User::factory()->create(['email' => 'admin@trypost.test', 'timezone' => 'UTC']);
    fakeStripeCoupons(['REAL']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

/**
 * Stripe answers the coupons given and refuses any other.
 *
 * @param  list<string>  $coupons
 */
function fakeStripeCoupons(array $coupons): void
{
    ApiRequestor::setHttpClient(new class($coupons) implements ClientInterface
    {
        /** @param  list<string>  $coupons */
        public function __construct(private array $coupons) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $id = (string) str($absUrl)->afterLast('/coupons/');

            if (in_array($id, $this->coupons, true)) {
                return [json_encode(['id' => $id, 'object' => 'coupon', 'percent_off' => 50, 'duration' => 'once']), 200, []];
            }

            return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => "No such coupon: '{$id}'"]]), 404, []];
        }
    });
}

/** @return array<string, mixed> */
function offerForm(array $overrides = []): array
{
    return [
        'slug' => 'spring-sale',
        'stripe_coupon_id' => 'REAL',
        'trial_days' => null,
        'starts_at' => CarbonImmutable::now('UTC')->toDateString(),
        'ends_at' => null,
        'max_redemptions' => null,
        'allow_promotion_codes' => false,
        'first_subscription_only' => true,
        'show_banner' => false,
        'requires_card' => true,
        ...$overrides,
    ];
}

test('lists every offer with its redemptions and the locales its copy is missing in', function () {
    Offer::factory()->create(['slug' => 'fm1']);
    Offer::factory()->create(['slug' => 'no-copy-yet']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.offers.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/billing/offers/Index')
            ->has('offers', 2)
            ->where('offers', fn ($offers) => collect($offers)->firstWhere('slug', 'fm1')['missing_copy'] === []
                && collect($offers)->firstWhere('slug', 'no-copy-yet')['missing_copy'] !== []),
        );
});

test('an admin creates an offer whose coupon exists in Stripe, and it is recorded', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), offerForm())
        ->assertRedirect(route('admin.billing.offers.index'));

    $offer = Offer::query()->where('slug', 'spring-sale')->firstOrFail();

    expect($offer->stripe_coupon_id)->toBe('REAL')
        ->and($offer->first_subscription_only)->toBeTrue()
        ->and($offer->starts_at->toDateString())->toBe(CarbonImmutable::now('UTC')->toDateString())
        ->and(Activity::query()->inLog(LogName::Admin)->where('event', 'offer.created')->exists())->toBeTrue();
});

test('an offer is refused when its coupon is not in Stripe, or when its parts do not go together', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), offerForm($overrides))
        ->assertSessionHasErrors($field);

    expect(Offer::query()->where('slug', 'spring-sale')->exists())->toBeFalse();
})->with([
    'a coupon Stripe does not have' => [['stripe_coupon_id' => 'GHOST'], 'stripe_coupon_id'],
    'a slug with spaces' => [['slug' => 'Spring Sale'], 'slug'],
    'a trial without a card and without days' => [['stripe_coupon_id' => null, 'requires_card' => false], 'trial_days'],
    'a trial without a card that carries a coupon' => [['trial_days' => 7, 'requires_card' => false], 'stripe_coupon_id'],
    'a coupon with the promotion code field' => [['allow_promotion_codes' => true], 'allow_promotion_codes'],
    'nothing to give' => [['stripe_coupon_id' => null], 'stripe_coupon_id'],
]);

test('saving an offer without touching its dates keeps their exact moment', function () {
    $startsAt = CarbonImmutable::now('UTC')->startOfDay()->addHours(15);
    $offer = Offer::factory()->create(['slug' => 'kept', 'stripe_coupon_id' => 'REAL', 'starts_at' => $startsAt]);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.offers.update', $offer), offerForm(['starts_at' => $startsAt->toDateString(), 'max_redemptions' => 10]))
        ->assertRedirect(route('admin.billing.offers.index'));

    expect($offer->refresh()->starts_at->equalTo($startsAt))->toBeTrue()
        ->and($offer->max_redemptions)->toBe(10)
        ->and($offer->slug)->toBe('kept');
});

test('an admin ends an offer now, and deletes only one nobody redeemed', function () {
    $redeemed = Offer::factory()->create(['slug' => 'used']);
    $user = User::factory()->create();
    $user->account->offers()->attach($redeemed->id, ['claimed_at' => now(), 'redeemed_at' => now()]);
    $unused = Offer::factory()->create(['slug' => 'unused']);

    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $redeemed))->assertRedirect();
    expect($redeemed->refresh()->ends_at->isPast() || $redeemed->ends_at->isCurrentSecond())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.billing.offers.destroy', $redeemed))->assertSessionHasErrors('offer');
    expect(Offer::query()->whereKey($redeemed->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.billing.offers.destroy', $unused))->assertRedirect();
    expect(Offer::query()->whereKey($unused->id)->exists())->toBeFalse();
});

test('a user who is not an admin cannot see or change offers', function () {
    $offer = Offer::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.billing.offers.index'))->assertForbidden();
    $this->post(route('admin.billing.offers.store'), offerForm())->assertForbidden();
    $this->delete(route('admin.billing.offers.destroy', $offer))->assertForbidden();
});
