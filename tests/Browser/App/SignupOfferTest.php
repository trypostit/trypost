<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Offer;
use App\Models\User;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    $this->offer = Offer::factory()->create(['slug' => 'black-friday-2026']);
});

test('an offer opened on the register page survives a trip to login and back to signup', function () {
    $page = visit(route('register', ['offer' => 'black-friday-2026']));
    waitFor($page, '@register-log-in-link');

    $page->click('@register-log-in-link');
    waitFor($page, '@login-sign-up-link');
    $page->assertPathIs(parse_url(route('login'), PHP_URL_PATH));

    $page->click('@login-sign-up-link');
    waitFor($page, '@register-name');
    $page->assertPathIs(parse_url(route('register'), PHP_URL_PATH));

    $page->type('@register-name', 'Offer Visitor')
        ->type('@register-email', 'offer-visitor@example.com')
        ->type('@register-password', strongPassword())
        ->click('@register-user-button');
    waitForPath($page, parse_url(route('app.welcome.persona'), PHP_URL_PATH));

    $page->assertNoJavaScriptErrors();

    expect(User::query()->where('email', 'offer-visitor@example.com')->firstOrFail()->account->offers()->pluck('offers.id')->all())
        ->toBe([$this->offer->id]);
});

test('an offer opened on the login page survives a trip to signup and back to login', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id, 'email' => 'returning@example.com']);
    $account->update(['owner_id' => $owner->id]);

    $page = visit(route('login', ['offer' => 'black-friday-2026']));
    waitFor($page, '@login-sign-up-link');

    $page->click('@login-sign-up-link');
    waitFor($page, '@register-log-in-link');
    $page->assertPathIs(parse_url(route('register'), PHP_URL_PATH));

    $page->click('@register-log-in-link');
    waitFor($page, '@login-email');
    $page->assertPathIs(parse_url(route('login'), PHP_URL_PATH));

    $page->type('@login-email', 'returning@example.com')
        ->type('@login-password', 'password')
        ->click('@login-submit');
    waitForPath($page, parse_url(route('app.welcome.persona'), PHP_URL_PATH));

    $page->assertNoJavaScriptErrors();

    expect($account->offers()->pluck('offers.id')->all())->toBe([$this->offer->id]);
});

test('a link to an unknown offer signs the visitor up without one', function () {
    $page = visit(route('register', ['offer' => 'no-such-offer']));
    waitFor($page, '@register-name');

    $page->type('@register-name', 'Plain Visitor')
        ->type('@register-email', 'plain-visitor@example.com')
        ->type('@register-password', strongPassword())
        ->click('@register-user-button');
    waitForPath($page, parse_url(route('app.welcome.persona'), PHP_URL_PATH));

    $page->assertNoJavaScriptErrors();

    expect(User::query()->where('email', 'plain-visitor@example.com')->firstOrFail()->account->offers()->count())->toBe(0);
});
