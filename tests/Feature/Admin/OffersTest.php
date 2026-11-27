<?php

declare(strict_types=1);

use App\Enums\Activity\LogName;
use App\Models\Activity;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_fake');
    $this->admin = backofficeAdmin(['timezone' => 'UTC']);
    adminFakeStripeCoupons(['REAL']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

/**
 * Stripe answers the coupons given and refuses any other.
 *
 * @param  list<string>  $coupons
 */
function adminFakeStripeCoupons(array $coupons): void
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
function adminOfferForm(array $overrides = []): array
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
        ->post(route('admin.billing.offers.store'), adminOfferForm())
        ->assertRedirect(route('admin.billing.offers.index'));

    $offer = Offer::query()->where('slug', 'spring-sale')->firstOrFail();

    expect($offer->stripe_coupon_id)->toBe('REAL')
        ->and($offer->first_subscription_only)->toBeTrue()
        ->and($offer->starts_at->toDateString())->toBe(CarbonImmutable::now('UTC')->toDateString())
        ->and(Activity::query()->inLog(LogName::Admin)->where('event', 'offer.created')->exists())->toBeTrue();
});

test('an offer is refused when its coupon is not in Stripe, or when its parts do not go together', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm($overrides))
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
        ->put(route('admin.billing.offers.update', $offer), adminOfferForm(['starts_at' => $startsAt->toDateString(), 'max_redemptions' => 10]))
        ->assertRedirect(route('admin.billing.offers.index'));

    expect($offer->refresh()->starts_at->equalTo($startsAt))->toBeTrue()
        ->and($offer->max_redemptions)->toBe(10)
        ->and($offer->slug)->toBe('kept');
});

test('an admin ends an offer now, and deletes only one nobody redeemed', function () {
    $this->freezeSecond();
    $redeemed = Offer::factory()->create(['slug' => 'used']);
    $user = User::factory()->create();
    $user->account->offers()->attach($redeemed->id, ['claimed_at' => now(), 'redeemed_at' => now()]);
    $unused = Offer::factory()->create(['slug' => 'unused']);

    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $redeemed))->assertRedirect();
    expect($redeemed->refresh()->ends_at->equalTo(now()))->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.billing.offers.destroy', $redeemed))->assertSessionHasErrors('offer');
    expect(Offer::query()->whereKey($redeemed->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)->delete(route('admin.billing.offers.destroy', $unused))->assertRedirect();
    expect(Offer::query()->whereKey($unused->id)->exists())->toBeFalse();
});

test('a user who is not an admin cannot see or change offers', function () {
    $offer = Offer::factory()->create();
    $this->actingAs(User::factory()->create());

    $this->get(route('admin.billing.offers.index'))->assertForbidden();
    $this->post(route('admin.billing.offers.store'), adminOfferForm())->assertForbidden();
    $this->delete(route('admin.billing.offers.destroy', $offer))->assertForbidden();
});

test('an offer can be set to start or end by the end of 2037 only', function (array $overrides, string $field) {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm($overrides))
        ->assertSessionHasErrors($field);

    expect(Offer::query()->where('slug', 'spring-sale')->exists())->toBeFalse();
})->with([
    'a start in 2038' => [['starts_at' => CarbonImmutable::parse('2037-12-31')->addDay()->toDateString()], 'starts_at'],
    'an end in 2038' => [['ends_at' => CarbonImmutable::parse('2037-12-31')->addDay()->toDateString()], 'ends_at'],
]);

test('an offer whose end comes before its start is refused', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm(['ends_at' => CarbonImmutable::now('UTC')->subDay()->toDateString()]))
        ->assertSessionHasErrors('ends_at');
});

test('an offer reusing a slug is refused', function () {
    Offer::factory()->create(['slug' => 'spring-sale']);

    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm())
        ->assertSessionHasErrors('slug');

    expect(Offer::query()->where('slug', 'spring-sale')->count())->toBe(1);
});

test('a trial without a card saves', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm(['stripe_coupon_id' => null, 'trial_days' => 7, 'requires_card' => false]))
        ->assertSessionHasNoErrors();

    $offer = Offer::query()->where('slug', 'spring-sale')->sole();

    expect($offer->requires_card)->toBeFalse()
        ->and($offer->trial_days)->toBe(7)
        ->and($offer->startsWithoutCard())->toBeTrue();
});

test('an offer that only shows the promotion code field saves', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm(['stripe_coupon_id' => null, 'allow_promotion_codes' => true]))
        ->assertSessionHasNoErrors();

    $offer = Offer::query()->where('slug', 'spring-sale')->sole();

    expect($offer->allow_promotion_codes)->toBeTrue()
        ->and($offer->stripe_coupon_id)->toBeNull();
});

test('a coupon cannot be checked while Stripe is unreachable', function () {
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }
    });

    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm())
        ->assertSessionHasErrors(['stripe_coupon_id' => "Couldn't reach Stripe to check this coupon. Try again."]);
});

test('editing an offer records what changed', function () {
    $offer = Offer::factory()->create(['slug' => 'kept', 'stripe_coupon_id' => 'REAL', 'max_redemptions' => null]);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.offers.update', $offer), adminOfferForm(['starts_at' => $offer->starts_at->toDateString(), 'max_redemptions' => 10]));

    $activity = Activity::query()->inLog(LogName::Admin)->forSubject($offer)->where('event', 'offer.updated')->sole();

    expect($activity->getProperty('changes'))->toHaveKey('max_redemptions', 10);
});

test('ending an offer records it and nobody can find it any more', function () {
    $offer = Offer::factory()->create(['slug' => 'ending']);

    expect(Offer::findAvailable('ending'))->not->toBeNull();

    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $offer))->assertRedirect();

    expect(Offer::findAvailable('ending'))->toBeNull()
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($offer)->where('event', 'offer.ended')->exists())->toBeTrue();
});

test('ending an offer that already ended keeps its end', function () {
    $endedAt = now()->subDays(3)->startOfSecond();
    $offer = Offer::factory()->create(['slug' => 'over', 'ends_at' => $endedAt]);

    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $offer))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $offer))->assertRedirect();

    expect($offer->refresh()->ends_at->equalTo($endedAt))->toBeTrue()
        ->and(Activity::query()->forSubject($offer)->exists())->toBeFalse();
});

test('deleting an offer nobody redeemed drops its claims and records it', function () {
    $offer = Offer::factory()->create(['slug' => 'claimed']);
    $user = User::factory()->create();
    $user->account->offers()->attach($offer->id, ['claimed_at' => now()]);

    $this->actingAs($this->admin)->delete(route('admin.billing.offers.destroy', $offer))->assertRedirect();

    expect($user->account->offers()->count())->toBe(0)
        ->and(Activity::query()->inLog(LogName::Admin)->where('event', 'offer.deleted')->sole()->getProperty('slug'))->toBe('claimed');
});
