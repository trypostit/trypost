<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Uri;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('the register form sends the zone, week start and clock the browser reports', function (string $locale, string $timezone, string $weekStart, string $timeFormat) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($locale)->withTimezone($timezone);
    waitForState($page, '@register-time-format', "element.value !== ''");

    $page->assertValue('@register-timezone', $timezone)
        ->assertValue('@register-week-start', $weekStart)
        ->assertValue('@register-time-format', $timeFormat)
        ->assertNoJavaScriptErrors();
})->with([
    'United States' => ['en-US', 'America/New_York', 'sunday', '12h'],
    'Germany' => ['de-DE', 'Europe/Berlin', 'monday', '24h'],
]);

test('the google and github buttons carry the detected preferences to the OAuth start', function () {
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('login'))->withLocale('en-US')->withTimezone('Asia/Tokyo');
    waitFor($page, '@social-login-github');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->all())
            ->toMatchArray(['locale' => 'en', 'timezone' => 'Asia/Tokyo', 'week_starts_on' => 'sunday', 'time_format' => '12h']);
    }

    $page->assertNoJavaScriptErrors();
});

test('the register form signs up in the browser language when the visitor picks none', function (string $browserLocale, string $expected) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('register'))->withLocale($browserLocale);
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->assertValue('@register-locale', $expected)
        ->assertNoJavaScriptErrors();
})->with([
    'Brazil' => ['pt-BR', 'pt-BR'],
    'Portugal, same base language' => ['pt-PT', 'pt-BR'],
    'Spain' => ['es-ES', 'es'],
    'unsupported falls back to the default' => ['sv-SE', 'en'],
]);

test('the google and github buttons carry the browser language to the OAuth start', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('register'))->withLocale('es-ES');
    waitFor($page, '@social-login-github');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->get('locale'))->toBe('es');
    }

    $page->assertNoJavaScriptErrors();
});

test('a language picked on the register page wins over the browser language', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('register'))->withLocale('es-ES');
    waitFor($page, '@language-picker-trigger');

    $page->click('@language-picker-trigger');
    waitFor($page, '@language-picker-option-pt-BR');
    $page->click('@language-picker-option-pt-BR');
    waitForState($page, '@register-locale', "element.value === 'pt-BR'");

    $page->assertValue('@register-locale', 'pt-BR');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->get('locale'))->toBe('pt-BR');
    }

    $page->assertNoJavaScriptErrors();
});

test('signing up with the email form creates the account in the browser language', function () {
    config()->set('trypost.self_hosted', false);
    fakePwnedPasswords();

    $page = visit(route('register'))->withLocale('pt-BR');
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->fill('@register-name', 'Bia')
        ->fill('@register-email', 'bia-browser@example.com')
        ->fill('@register-password', strongPassword())
        ->click('@register-user-button');
    waitForPath($page, parse_url(route('app.welcome.persona'), PHP_URL_PATH));

    expect(User::query()->where('email', 'bia-browser@example.com')->sole()->locale)->toBe(Locale::PortugueseBrazil);
    $page->assertNoJavaScriptErrors();
});

test('a new person who starts on the login page signs up with google or github in the browser language', function (string $network, string $driver) {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $page = visit(route('login'))->withLocale('es-ES');
    waitFor($page, "@social-login-{$network}");
    $href = $page->attribute("@social-login-{$network}", 'href');
    $page->assertNoJavaScriptErrors();

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = "{$network}-from-login";
    $socialiteUser->name = 'New Person';
    $socialiteUser->email = "{$network}-from-login@example.com";
    Socialite::shouldReceive('driver')->with($driver)->andReturn($socialite = Mockery::mock());
    $socialite->shouldReceive('redirect')->andReturn(redirect('/'));
    $socialite->shouldReceive('scopes')->andReturnSelf();
    $socialite->shouldReceive('user')->andReturn($socialiteUser);

    $this->get($href);
    $this->get(route("auth.{$network}.callback"))->assertRedirect(route('app.welcome', absolute: false));

    expect(User::query()->where('email', "{$network}-from-login@example.com")->sole()->locale)->toBe(Locale::Spanish);
})->with([
    'google' => ['google', 'google-auth'],
    'github' => ['github', 'github'],
]);

test('the register page opened from an invite sends the browser language with the invite', function () {
    config()->set('trypost.google_auth_enabled', true);
    config()->set('trypost.github_auth_enabled', true);

    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $owner->id]);
    $invite = Invite::factory()->create([
        'account_id' => $account->id,
        'invited_by' => $owner->id,
        'email' => 'invitee-browser@example.com',
        'workspaces' => [$workspace->id],
    ]);

    $page = visit(route('register', ['invite' => $invite->id, 'email' => 'invitee-browser@example.com']))->withLocale('pt-BR');
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->assertValue('@register-locale', 'pt-BR');

    foreach (['@social-login-google', '@social-login-github'] as $button) {
        expect(Uri::of($page->attribute($button, 'href'))->query()->all())
            ->toMatchArray(['invite' => $invite->id, 'locale' => 'pt-BR']);
    }

    $page->assertNoJavaScriptErrors();
});

test('with several browser languages the first one TryPost supports wins', function (array $browserLanguages, string $expected) {
    config()->set('trypost.self_hosted', false);

    $page = visit(route('login'))->withLocale('en-US');
    waitFor($page, '@login-sign-up-link');

    $languages = json_encode($browserLanguages);
    $page->script("Object.defineProperty(navigator, 'languages', { get: () => {$languages} })");
    $page->click('@login-sign-up-link');
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->assertValue('@register-locale', $expected)
        ->assertNoJavaScriptErrors();
})->with([
    'English first, then Portuguese' => [['en-US', 'pt-BR'], 'en'],
    'unsupported first, then Portuguese' => [['sv-SE', 'pt-BR'], 'pt-BR'],
    'Portugal first, then Spanish' => [['pt-PT', 'es-ES'], 'pt-BR'],
    'none supported' => [['sv-SE', 'fi-FI'], 'en'],
]);
