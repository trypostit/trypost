<?php

declare(strict_types=1);

use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    config()->set('services.affonso.id', 'test-affonso-id');
    config()->set('services.affonso.script_url', 'data:text/javascript,window.Affonso={signup:function(user){window.__affonsoSignups=(window.__affonsoSignups||[]).concat([user])}}');
});

test('signing up with the email form records the new user with the affonso pixel', function () {
    fakePwnedPasswords();

    $page = visit(route('register'));
    waitForState($page, '@register-locale', "element.value !== ''");

    $page->fill('@register-name', 'Referred')
        ->fill('@register-email', 'referred-email@example.com')
        ->fill('@register-password', strongPassword())
        ->click('@register-user-button');
    waitForCondition($page, "window.__affonsoSignups?.[0]?.email === 'referred-email@example.com'");

    expect($page->script('window.__affonsoSignups.length'))->toBe(1);
    $page->assertNoJavaScriptErrors();
});

test('signing up with google records the new user with the affonso pixel on the page it lands on', function () {
    config()->set('trypost.google_auth_enabled', true);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = 'google-referred';
    $socialiteUser->name = 'Referred';
    $socialiteUser->email = 'referred-google@example.com';
    Socialite::shouldReceive('driver')->with('google-auth')->andReturn($socialite = Mockery::mock());
    $socialite->shouldReceive('scopes')->andReturnSelf();
    $socialite->shouldReceive('user')->andReturn($socialiteUser);

    $page = visit(route('login'))->navigate(route('auth.google.callback'));
    waitForCondition($page, "window.__affonsoSignups?.[0]?.email === 'referred-google@example.com'");

    expect($page->script('window.__affonsoSignups.length'))->toBe(1);
    $page->assertNoJavaScriptErrors();
});
