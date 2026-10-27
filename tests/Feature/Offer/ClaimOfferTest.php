<?php

declare(strict_types=1);

use App\Actions\Offer\ClaimOffer;
use App\Http\Middleware\App\CaptureOffer;
use App\Models\Account;
use App\Models\Offer;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

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

test('a guest\'s wrong-case slug is not kept in the session', function () {
    $this->get(route('register', ['offer' => 'BLACK-FRIDAY-2026']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);
});

test('a signed-in owner claims without going through the session', function () {
    $owner = offerTestOwner();

    $this->actingAs($owner)
        ->get(route('app.welcome.plan', ['offer' => 'black-friday-2026']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);

    expect($owner->account->offers()->count())->toBe(1);
});

test('a user without an account claims nothing and keeps no slug', function () {
    $user = User::factory()->create(['account_id' => null]);

    $this->actingAs($user)
        ->get(route('app.welcome.subscription-required', ['offer' => 'black-friday-2026']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);

    $this->assertDatabaseCount('account_offer', 0);
});

test('a guest\'s offer is claimed when they sign up with google', function () {
    config(['trypost.google_auth_enabled' => true]);
    $this->get(route('auth.google.redirect', ['offer' => 'black-friday-2026']));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'g-offer';
    $socialiteUser->name = 'Google Offer';
    $socialiteUser->email = 'google-offer@example.com';
    Socialite::shouldReceive('driver')->with('google-auth')->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->followingRedirects()->get(route('auth.google.callback'));

    expect(User::query()->where('email', 'google-offer@example.com')->firstOrFail()->account->offers()->pluck('offers.id')->all())
        ->toBe([$this->offer->id]);
});

test('a guest\'s offer is claimed when they sign up with github', function () {
    config(['trypost.github_auth_enabled' => true]);
    $this->get(route('auth.github.redirect', ['offer' => 'black-friday-2026']));

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'gh-offer';
    $socialiteUser->name = 'GitHub Offer';
    $socialiteUser->email = 'github-offer@example.com';
    $socialiteUser->nickname = 'github-offer';
    Socialite::shouldReceive('driver')->with('github')->andReturn($driver = Mockery::mock());
    $driver->shouldReceive('user')->andReturn($socialiteUser);

    $this->followingRedirects()->get(route('auth.github.callback'));

    expect(User::query()->where('email', 'github-offer@example.com')->firstOrFail()->account->offers()->pluck('offers.id')->all())
        ->toBe([$this->offer->id]);
});

test('the offer survives moving from the register page to login', function () {
    $owner = offerTestOwner();

    $this->get(route('register', ['offer' => 'black-friday-2026']));
    $this->get(route('login'));
    $this->get(route('register'));

    $this->followingRedirects()->post(route('login.store'), [
        'email' => $owner->email,
        'password' => 'password',
    ]);

    expect($owner->account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('a former customer cannot claim a first-subscription offer', function () {
    $owner = User::factory()->create();
    $owner->account->update(['owner_id' => $owner->id]);
    $owner->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_former',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_123',
        'ends_at' => now()->subDay(),
    ]);
    $offer = Offer::factory()->firstSubscriptionOnly()->create(['slug' => 'fm1']);

    expect(ClaimOffer::execute($owner->account->fresh(), 'fm1'))->toBeNull()
        ->and($owner->account->offers()->whereKey($offer->id)->exists())->toBeFalse();
});

test('a waiting offer is still claimed when the first signed-in page links to one that cannot be', function () {
    $this->withSession([CaptureOffer::SESSION_KEY => 'black-friday-2026']);
    $owner = offerTestOwner();

    $this->actingAs($owner)
        ->get(route('app.welcome.plan', ['offer' => 'no-such-offer']))
        ->assertSessionMissing(CaptureOffer::SESSION_KEY);

    expect($owner->account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('an offer limited to two places takes two redemptions and refuses a third account', function () {
    $offer = Offer::factory()->limitedTo(2)->create();
    $first = offerTestOwner();
    $second = offerTestOwner();
    $third = offerTestOwner();

    expect(ClaimOffer::execute($first->account, $offer->slug)?->is($offer))->toBeTrue();
    $first->account->offers()->updateExistingPivot($offer->id, ['redeemed_at' => now()]);

    expect(ClaimOffer::execute($second->account, $offer->slug)?->is($offer))->toBeTrue();
    $second->account->offers()->updateExistingPivot($offer->id, ['redeemed_at' => now()]);

    expect(ClaimOffer::execute($third->account, $offer->slug))->toBeNull()
        ->and($third->account->offers()->count())->toBe(0)
        ->and($third->account->currentOffer())->toBeNull();
});

test('an owner who claimed an offer before its last place went pays the full price', function () {
    $offer = Offer::factory()->limitedTo(1)->create();
    $owner = offerTestOwner();
    ClaimOffer::execute($owner->account, $offer->slug);

    $other = offerTestOwner();
    $other->account->offers()->attach($offer, ['claimed_at' => now(), 'redeemed_at' => now()]);

    expect($owner->account->offers()->count())->toBe(1)
        ->and($owner->account->currentOffer())->toBeNull();
});
