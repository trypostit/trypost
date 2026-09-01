<?php

declare(strict_types=1);

test('the login screen shows the legal sentence to a logged out visitor', function () {
    visit(route('login'))
        ->assertVisible('@legal-links')
        ->assertSeeLink('Terms of Service')
        ->assertSeeLink('Privacy Policy')
        ->assertNoJavaScriptErrors();
});

test('the register screen shows the legal sentence to a logged out visitor', function () {
    config(['trypost.self_hosted' => false]);

    visit(route('register'))
        ->assertVisible('@legal-links')
        ->assertSeeLink('Terms of Service')
        ->assertSeeLink('Privacy Policy')
        ->assertNoJavaScriptErrors();
});
