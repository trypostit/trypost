<?php

declare(strict_types=1);

function waitForAuthTitlesRequirementMet(mixed $page, string $key, string $met): void
{
    waitForState($page, "@password-requirement-{$key}[data-met=\"{$met}\"]", 'true');
}

beforeEach(fn () => config(['trypost.self_hosted' => false]));

test('login shows the new title without a description and links to register', function () {
    $page = visit(route('login'));
    waitFor($page, '@login-sign-up-link');

    $page->assertSeeIn('@auth-title', 'Log in to TryPost')
        ->assertDontSee('Enter your email and password below to log in')
        ->click('@login-sign-up-link');

    waitFor($page, '@register-log-in-link');

    $page->assertSeeIn('@auth-title', 'Create a TryPost account')
        ->assertDontSee('Create your account and start scheduling posts across every network.')
        ->click('@register-log-in-link');

    waitFor($page, '@login-sign-up-link');

    $page->assertSeeIn('@auth-title', 'Log in to TryPost')->assertNoJavaScriptErrors();
});

test('typing a password ticks each requirement', function () {
    $page = visit(route('register'));
    waitFor($page, '@password-requirements');

    $page->assertSeeIn('@password-requirement-min', 'At least 12 characters')
        ->assertAttribute('@password-requirement-min', 'data-met', 'false')
        ->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@register-password', 'abc');
    waitForAuthTitlesRequirementMet($page, 'mixed_case', 'false');
    $page->assertAttribute('@password-requirement-mixed_case', 'data-met', 'false');

    $page->type('@register-password', 'abcD');
    waitForAuthTitlesRequirementMet($page, 'mixed_case', 'true');
    $page->assertAttribute('@password-requirement-mixed_case', 'data-met', 'true')
        ->assertAttribute('@password-requirement-number', 'data-met', 'false');

    $page->type('@register-password', 'abcD1');
    waitForAuthTitlesRequirementMet($page, 'number', 'true');
    $page->assertAttribute('@password-requirement-number', 'data-met', 'true')
        ->assertAttribute('@password-requirement-symbol', 'data-met', 'false');

    $page->type('@register-password', 'abcD1!');
    waitForAuthTitlesRequirementMet($page, 'symbol', 'true');
    $page->assertAttribute('@password-requirement-symbol', 'data-met', 'true')
        ->assertAttribute('@password-requirement-min', 'data-met', 'false');

    $page->type('@register-password', 'abcD1!abcdef');
    waitForAuthTitlesRequirementMet($page, 'min', 'true');
    $page->assertAttribute('@password-requirement-min', 'data-met', 'true')
        ->assertNoJavaScriptErrors();
});
