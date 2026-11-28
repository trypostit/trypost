<?php

declare(strict_types=1);

use App\Actions\Admin\Offers\DeleteOffer;
use App\Actions\Admin\Offers\ListOffers;
use App\Enums\Activity\LogName;
use App\Enums\Offer\OfferState;
use App\Enums\User\Locale;
use App\Models\Activity;
use App\Models\Offer;
use App\Models\User;
use App\Rules\StripeCouponExists;
use App\Support\Admin\OfferCopy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;

beforeEach(function () {
    config()->set('cashier.secret', 'sk_test_fake');
    $this->admin = admin(['timezone' => 'UTC']);
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

/**
 * Sends the offer form, creating an offer or editing one the test already has.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminSendOfferForm(string $action, array $overrides): TestResponse
{
    if ($action === 'update') {
        $offer = Offer::factory()->create(['slug' => 'spring-sale', 'stripe_coupon_id' => 'REAL', 'trial_days' => null]);

        return test()->put(route('admin.billing.offers.update', $offer), adminOfferForm($overrides));
    }

    return test()->post(route('admin.billing.offers.store'), adminOfferForm($overrides));
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
        ->from(route('admin.billing.offers.index'))
        ->post(route('admin.billing.offers.store'), adminOfferForm())
        ->assertRedirect(route('admin.billing.offers.index'));

    $offer = Offer::query()->where('slug', 'spring-sale')->firstOrFail();

    expect($offer->stripe_coupon_id)->toBe('REAL')
        ->and($offer->first_subscription_only)->toBeTrue()
        ->and($offer->starts_at->toDateString())->toBe(CarbonImmutable::now('UTC')->toDateString())
        ->and(Activity::query()->inLog(LogName::Admin)->where('event', 'offer.created')->exists())->toBeTrue();
});

test('every offer action goes back to the tab it was sent from', function (string $action) {
    $tab = route('admin.billing.offers.index', ['tab' => 'scheduled']);
    $offer = Offer::factory()->create(['slug' => 'tabbed', 'stripe_coupon_id' => 'REAL', 'starts_at' => now()->addDays(3)]);
    $admin = $this->actingAs($this->admin)->from($tab);

    $response = match ($action) {
        'create' => $admin->post(route('admin.billing.offers.store'), adminOfferForm()),
        'edit' => $admin->put(route('admin.billing.offers.update', $offer), adminOfferForm(['starts_at' => $offer->starts_at->toDateString()])),
        'end' => $admin->post(route('admin.billing.offers.end.store', $offer)),
        'delete' => $admin->delete(route('admin.billing.offers.destroy', $offer)),
    };

    $response->assertSessionHasNoErrors()->assertRedirect($tab);
})->with(['create', 'edit', 'end', 'delete']);

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
        ->from(route('admin.billing.offers.index'))
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

test('an offer whose parts do not go together is refused, created or edited', function (string $action, array $overrides, string $field, string $message) {
    $this->actingAs($this->admin);

    adminSendOfferForm($action, $overrides)->assertSessionHasErrors([$field => $message]);

    expect(Offer::query()->where('slug', 'spring-sale')->where('stripe_coupon_id', 'REAL')->count())->toBe($action === 'update' ? 1 : 0);
})->with(['create', 'update'])->with([
    'a trial without a card of no days' => [['stripe_coupon_id' => null, 'trial_days' => 0, 'requires_card' => false], 'trial_days', 'A trial without a card needs at least one day.'],
    'a coupon with the promotion code field' => [['allow_promotion_codes' => true], 'allow_promotion_codes', 'Stripe takes a coupon or the promotion code field, not both: the coupon wins.'],
    'nothing to give' => [['stripe_coupon_id' => null], 'stripe_coupon_id', 'An offer needs a coupon, a trial or the promotion code field.'],
]);

test('a trial without a card of one day saves, created or edited', function (string $action) {
    $this->actingAs($this->admin);

    adminSendOfferForm($action, ['stripe_coupon_id' => null, 'trial_days' => 1, 'requires_card' => false])->assertSessionHasNoErrors();

    expect(Offer::query()->where('slug', 'spring-sale')->sole()->startsWithoutCard())->toBeTrue();
})->with(['create', 'update']);

test('ending a scheduled offer starts and ends it now, so it never ran', function () {
    $this->freezeSecond();
    $offer = Offer::factory()->create(['slug' => 'later', 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(10)]);

    $this->actingAs($this->admin)->post(route('admin.billing.offers.end.store', $offer))->assertRedirect();

    $offer->refresh();

    expect($offer->starts_at->equalTo(now()))->toBeTrue()
        ->and($offer->ends_at->equalTo(now()))->toBeTrue()
        ->and($offer->state())->toBe(OfferState::Ended)
        ->and(Offer::findAvailable('later'))->toBeNull()
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($offer)->where('event', 'offer.ended')->exists())->toBeTrue();
});

test('an offer is scheduled, live, full or ended, and the list sends its state', function (Closure $makeOffer, OfferState $state) {
    $this->freezeSecond();
    $offer = $makeOffer();

    expect($offer->fresh()->state())->toBe($state)
        ->and(Offer::query()->withCount('redemptions')->findOrFail($offer->id)->state())->toBe($state);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.offers.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('offers.0.state', $state->value));
})->with([
    'starting later' => [fn (): Offer => Offer::factory()->create(['starts_at' => now()->addDay()]), OfferState::Scheduled],
    'started without an end' => [fn (): Offer => Offer::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => null]), OfferState::Live],
    'started with a place left' => [fn (): Offer => adminOfferRedeemedBy(Offer::factory()->create(['starts_at' => now()->subDay(), 'max_redemptions' => 2]), 1), OfferState::Live],
    'every place redeemed' => [fn (): Offer => adminOfferRedeemedBy(Offer::factory()->create(['starts_at' => now()->subDay(), 'max_redemptions' => 1]), 1), OfferState::Full],
    'ending now' => [fn (): Offer => Offer::factory()->create(['starts_at' => now()->subDay(), 'ends_at' => now()]), OfferState::Ended],
    'ended and full' => [fn (): Offer => adminOfferRedeemedBy(Offer::factory()->create(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'max_redemptions' => 1]), 1), OfferState::Ended],
]);

/** Redeems the offer for that many new accounts. */
function adminOfferRedeemedBy(Offer $offer, int $accounts): Offer
{
    User::factory()->count($accounts)->create()->each(fn (User $user) => $user->account->offers()->attach($offer->id, ['claimed_at' => now(), 'redeemed_at' => now()]));

    return $offer;
}

test('an admin\'s days are whole days of their own zone, and read back as the same days', function () {
    $this->admin->update(['timezone' => 'America/Sao_Paulo']);
    $firstDay = now('America/Sao_Paulo')->addDays(2)->startOfDay();
    $lastDay = $firstDay->addDays(5);

    $this->actingAs($this->admin)
        ->post(route('admin.billing.offers.store'), adminOfferForm(['starts_at' => $firstDay->toDateString(), 'ends_at' => $lastDay->toDateString()]))
        ->assertSessionHasNoErrors();

    $offer = Offer::query()->where('slug', 'spring-sale')->sole();

    expect($offer->starts_at->equalTo($firstDay->utc()))->toBeTrue()
        ->and($offer->ends_at->equalTo($lastDay->endOfDay()->startOfSecond()->utc()))->toBeTrue();

    $this->actingAs($this->admin)
        ->get(route('admin.billing.offers.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('offers.0.starts_on', $firstDay->toDateString())
            ->where('offers.0.ends_on', $lastDay->toDateString()),
        );
});

test('editing keeps the end\'s exact moment on the same day and moves it to the end of another day', function () {
    $this->admin->update(['timezone' => 'America/Sao_Paulo']);
    $startsAt = now('America/Sao_Paulo')->startOfDay();
    $endsAt = $startsAt->addDays(10)->addHours(15);
    $offer = Offer::factory()->create(['slug' => 'kept', 'stripe_coupon_id' => 'REAL', 'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc()]);
    $form = adminOfferForm(['starts_at' => $startsAt->toDateString(), 'ends_at' => $endsAt->toDateString()]);

    $this->actingAs($this->admin)->put(route('admin.billing.offers.update', $offer), $form)->assertSessionHasNoErrors();

    expect($offer->refresh()->ends_at->equalTo($endsAt))->toBeTrue()
        ->and($offer->starts_at->equalTo($startsAt))->toBeTrue();

    $otherDay = $endsAt->addDays(2);

    $this->actingAs($this->admin)
        ->put(route('admin.billing.offers.update', $offer), [...$form, 'ends_at' => $otherDay->toDateString()])
        ->assertSessionHasNoErrors();

    expect($offer->refresh()->ends_at->equalTo($otherDay->endOfDay()->startOfSecond()))->toBeTrue();
});

test('deleting re-checks the redemptions itself, so an offer redeemed since the request stays', function () {
    $offer = Offer::factory()->create(['slug' => 'raced']);
    adminOfferRedeemedBy($offer, 1);

    expect(fn () => app(DeleteOffer::class)->execute($this->admin, $offer))
        ->toThrow(ValidationException::class, DeleteOffer::REDEEMED_MESSAGE);

    expect(Offer::query()->whereKey($offer->id)->exists())->toBeTrue()
        ->and(Activity::query()->forSubject($offer)->exists())->toBeFalse();
});

test('deleting locks the offer\'s claims, the rows a redemption writes, before re-checking them', function () {
    $offer = Offer::factory()->create(['slug' => 'locked']);
    User::factory()->create()->account->offers()->attach($offer->id, ['claimed_at' => now()]);
    $statements = collect();
    DB::listen(fn (QueryExecuted $query) => $statements->push(Str::lower($query->sql)));

    app(DeleteOffer::class)->execute($this->admin, $offer);

    expect($statements->contains(fn (string $sql): bool => Str::contains($sql, 'account_offer') && Str::endsWith($sql, 'for update')))->toBeTrue()
        ->and(Offer::query()->whereKey($offer->id)->exists())->toBeFalse();
});

test('a coupon passes only when Stripe has it', function (string $coupon, ?string $message) {
    $validator = Validator::make(['coupon' => $coupon], ['coupon' => [new StripeCouponExists]]);

    expect($validator->errors()->first('coupon'))->toBe((string) $message);
})->with([
    'a coupon Stripe has' => ['REAL', null],
    'a coupon Stripe does not have' => ['GHOST', 'Stripe has no coupon with this id in this account.'],
]);

test('a coupon fails while Stripe is unreachable', function () {
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            throw new ApiConnectionException('Could not connect to Stripe.');
        }
    });

    $validator = Validator::make(['coupon' => 'REAL'], ['coupon' => [new StripeCouponExists]]);

    expect($validator->errors()->first('coupon'))->toBe("Couldn't reach Stripe to check this coupon. Try again.");
});

test('an offer showing its banner also needs its name and headline in every locale', function () {
    $everyLocale = collect(Locale::cases())->map(fn (Locale $locale): string => $locale->value)->all();

    expect(OfferCopy::missingLocales(Offer::factory()->make(['slug' => 'ft7d', 'show_banner' => false])))->toBe([])
        ->and(OfferCopy::missingLocales(Offer::factory()->make(['slug' => 'ft7d', 'show_banner' => true])))->toBe($everyLocale)
        ->and(OfferCopy::missingLocales(Offer::factory()->make(['slug' => 'fm1', 'show_banner' => true])))->toBe([]);
});

test('the offers are listed the latest start first, each with its claims and redemptions counted', function () {
    $earlier = Offer::factory()->create(['slug' => 'earlier', 'starts_at' => now()->subDays(10)]);
    $later = adminOfferRedeemedBy(Offer::factory()->create(['slug' => 'later', 'starts_at' => now()->subDay()]), 2);
    User::factory()->create()->account->offers()->attach($later->id, ['claimed_at' => now()]);
    Offer::factory()->create(['slug' => 'b-same-day', 'starts_at' => $earlier->starts_at]);
    Offer::factory()->create(['slug' => 'a-same-day', 'starts_at' => $earlier->starts_at]);

    expect(ListOffers::execute()->pluck('slug')->all())->toBe(['later', 'a-same-day', 'b-same-day', 'earlier']);

    $this->actingAs($this->admin)
        ->get(route('admin.billing.offers.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('offers.0.slug', 'later')
            ->where('offers.0.claimed', 3)
            ->where('offers.0.redeemed', 2)
            ->where('offers.3.claimed', 0)
            ->where('offers.3.redeemed', 0),
        );
});
