<?php

declare(strict_types=1);

use App\Actions\Offer\ClaimOffer;
use App\Http\Middleware\App\CaptureOffer;
use App\Models\Account;
use App\Models\Offer;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->offer = Offer::factory()->create(['slug' => 'black-friday-2026']);
});

function offerTestOwner(): User
{
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);
    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->refresh();
}

function offerTestSubscribe(Account $account, string $status = 'active'): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => $status,
        'stripe_price' => 'price_123',
        'ends_at' => $status === 'canceled' ? now()->subDay() : null,
    ]);
}

test('a guest\'s offer is claimed when they register', function () {
    $this->get(route('register', ['offer' => 'black-friday-2026']));

    $this->followingRedirects()->post(route('register.store'), [
        'name' => 'Offer User',
        'email' => 'offer@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    $account = User::query()->where('email', 'offer@example.com')->firstOrFail()->account;

    expect($account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('claims a pending offer after login', function () {
    $owner = offerTestOwner();
    offerTestSubscribe($owner->account, 'canceled');

    $this->get(route('login', ['offer' => 'black-friday-2026']));

    $this->followingRedirects()->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'password',
    ]);

    expect($owner->account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('a logged-in owner without a subscription claims on any page', function () {
    $owner = offerTestOwner();

    $this->actingAs($owner)->get(route('app.welcome.plan', ['offer' => 'black-friday-2026']));

    expect($owner->account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('ignores accounts with an active subscription', function () {
    $owner = offerTestOwner();
    offerTestSubscribe($owner->account);

    $this->actingAs($owner)->get(route('app.calendar', ['offer' => 'black-friday-2026']));

    expect($owner->account->offers()->count())->toBe(0);
});

test('ignores members who are not the owner', function () {
    $owner = offerTestOwner();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    $this->actingAs($member)
        ->get(route('app.welcome.subscription-required', ['offer' => 'black-friday-2026']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);

    expect($owner->account->offers()->count())->toBe(0);
});

test('ignores unknown, upcoming, expired and full offers', function (Closure $makeOffer) {
    $slug = $makeOffer();
    $owner = offerTestOwner();

    $this->actingAs($owner)->get(route('app.welcome.plan', ['offer' => $slug]));

    expect($owner->account->offers()->count())->toBe(0);
})->with([
    'unknown' => fn () => fn (): string => 'no-such-offer',
    'upcoming' => fn () => fn (): string => Offer::factory()->upcoming()->create()->slug,
    'expired' => fn () => fn (): string => Offer::factory()->expired()->create()->slug,
    'full' => fn () => function (): string {
        $offer = Offer::factory()->limitedTo(1)->create();
        $offer->accounts()->attach(Account::factory()->create(), ['claimed_at' => now(), 'redeemed_at' => now()]);

        return $offer->slug;
    },
]);

test('ignores a non-string or blank offer', function (array $query) {
    $this->get(route('register', $query))
        ->assertOk()
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);
})->with([
    'array' => [['offer' => ['black-friday-2026']]],
    'blank' => [['offer' => '']],
    'huge' => [['offer' => str_repeat('a', 10_000)]],
]);

test('matches the slug exactly', function () {
    $owner = offerTestOwner();

    expect(ClaimOffer::execute($owner->account, 'BLACK-FRIDAY-2026'))->toBeNull()
        ->and($owner->account->offers()->count())->toBe(0);
});

test('does not re-offer a redeemed offer', function () {
    $owner = offerTestOwner();
    $redeemedAt = now()->subMonth()->startOfSecond();
    $owner->account->offers()->attach($this->offer, ['claimed_at' => $redeemedAt, 'redeemed_at' => $redeemedAt]);

    expect(ClaimOffer::execute($owner->account, 'black-friday-2026'))->toBeNull()
        ->and($owner->account->offers()->first()->pivot->redeemed_at)->toEqual($redeemedAt->toDateTimeString());
});

test('re-claiming refreshes claimed_at', function () {
    $owner = offerTestOwner();

    $this->travelTo(now()->subDay());
    ClaimOffer::execute($owner->account, 'black-friday-2026');
    $this->travelBack();

    $secondClaim = now()->startOfSecond();
    $this->travelTo($secondClaim);
    ClaimOffer::execute($owner->account, 'black-friday-2026');

    expect($owner->account->offers()->first()->pivot->claimed_at)->toEqual($secondClaim->toDateTimeString());
});

test('self-hosted never claims', function () {
    config(['trypost.self_hosted' => true]);
    $owner = offerTestOwner();

    $this->actingAs($owner)->get(route('app.calendar', ['offer' => 'black-friday-2026']));

    expect($owner->account->offers()->count())->toBe(0);
});

test('a guest\'s link to an offer that has not started is not kept for later', function () {
    Offer::factory()->create(['slug' => 'cyber-monday-2026', 'starts_at' => now()->addHour()]);

    $this->get(route('register', ['offer' => 'cyber-monday-2026']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);

    $this->travel(2)->hours();

    $this->followingRedirects()->post(route('register.store'), [
        'name' => 'Early User',
        'email' => 'early@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ]);

    expect(User::query()->where('email', 'early@example.com')->firstOrFail()->account->offers()->count())->toBe(0);
});

test('a claim that lands while another is being made does not fail', function () {
    $owner = offerTestOwner();
    $inserted = false;

    DB::listen(function (QueryExecuted $query) use ($owner, &$inserted): void {
        if (! $inserted && str_contains($query->sql, 'account_offer') && str_contains($query->sql, 'account_id') && str_starts_with(strtolower($query->sql), 'select')) {
            $inserted = true;
            DB::table('account_offer')->insert(['account_id' => $owner->account_id, 'offer_id' => $this->offer->id, 'claimed_at' => now()->subMinute()]);
        }
    });

    expect(ClaimOffer::execute($owner->account, 'black-friday-2026')?->id)->toBe($this->offer->id)
        ->and($owner->account->offers()->count())->toBe(1);
});
