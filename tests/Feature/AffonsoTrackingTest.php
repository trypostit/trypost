<?php

declare(strict_types=1);

use App\Jobs\Affonso\RecordAffiliateSignup;
use App\Listeners\RecordAffiliateSignupListener;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

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

function registerReferredUser(): void
{
    test()->post(route('register.store'), [
        'name' => 'Referred User',
        'email' => 'referred@example.com',
        'password' => strongPassword(),
        'password_confirmation' => strongPassword(),
        'locale' => 'en',
    ])->assertRedirect();
}

test('a referred signup queues its lead with the click id the pixel left unencrypted', function () {
    config(['trypost.self_hosted' => false, 'services.affonso.api_key' => 'sk_live_test']);
    Queue::fake();

    test()->withUnencryptedCookie('affonso_referral', 'clk_123');
    registerReferredUser();

    $user = User::where('email', 'referred@example.com')->firstOrFail();

    Queue::assertPushed(RecordAffiliateSignup::class, fn (RecordAffiliateSignup $job): bool => $job->userId === $user->id
        && $job->clickId === 'clk_123');
});

test('a signup without a referral cookie queues no lead', function () {
    config(['trypost.self_hosted' => false, 'services.affonso.api_key' => 'sk_live_test']);
    Queue::fake();

    registerReferredUser();

    Queue::assertNotPushed(RecordAffiliateSignup::class);
});

test('a referred signup queues no lead without an affonso api key', function () {
    config(['trypost.self_hosted' => false, 'services.affonso.api_key' => null]);
    Queue::fake();

    test()->withUnencryptedCookie('affonso_referral', 'clk_123');
    registerReferredUser();

    Queue::assertNotPushed(RecordAffiliateSignup::class);
});

test('every signup, email, google or github, reaches the affonso listener', function () {
    Event::fake();

    Event::assertListening(Registered::class, RecordAffiliateSignupListener::class);
});
