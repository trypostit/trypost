<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Inertia\Support\SessionKey;

test('every page loads the affonso pixel when an id is set', function () {
    config([
        'services.affonso.id' => 'test-affonso-id',
        'services.affonso.cookie_duration' => 30,
    ]);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('src="https://cdn.affonso.io/js/pixel.min.js"', false)
        ->assertSee('data-affonso="test-affonso-id"', false)
        ->assertSee('data-cookie_duration="30"', false);
});

test('pages skip the affonso pixel without an id', function () {
    config(['services.affonso.id' => null]);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-affonso', false);
});

test('a signup hands the new user to the affonso pixel on the next page', function () {
    config(['trypost.self_hosted' => false, 'services.affonso.id' => 'test-affonso-id']);

    $this->post(route('register.store'), [
        'name' => 'Referred User',
        'email' => 'referred@example.com',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
        'locale' => 'en',
    ])->assertRedirect();

    $user = User::where('email', 'referred@example.com')->firstOrFail();

    expect(session(SessionKey::FLASH_DATA))->toBe([
        'affiliateSignup' => ['email' => 'referred@example.com', 'externalUserId' => $user->id],
    ]);
});

test('a signup flashes nothing for affonso without an id', function () {
    config(['trypost.self_hosted' => false, 'services.affonso.id' => null]);

    $this->post(route('register.store'), [
        'name' => 'Referred User',
        'email' => 'referred@example.com',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
        'locale' => 'en',
    ])->assertRedirect();

    expect(session(SessionKey::FLASH_DATA))->toBeNull();
});

test('the affiliate referral cookie the pixel sets is read without decryption', function () {
    expect(app(EncryptCookies::class)->isDisabled('affonso_referral'))->toBeTrue();
});
