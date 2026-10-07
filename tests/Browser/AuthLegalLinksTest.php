<?php

declare(strict_types=1);

test('the register screen shows the email form open next to the social buttons', function () {
    config(['trypost.self_hosted' => false, 'trypost.google_auth_enabled' => true]);

    $page = visit(route('register'));
    $page->script('new Promise((resolve) => { const check = () => document.querySelector("[data-testid=register-name]") ? resolve(true) : requestAnimationFrame(check); check(); })');

    $page->assertVisible('@register-name')
        ->assertVisible('@register-email')
        ->assertMissing('@register-email-toggle')
        ->assertNoJavaScriptErrors();
});
