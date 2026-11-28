<?php

declare(strict_types=1);

test('the login form uses the standard control height and fits a 375px phone', function () {
    config(['trypost.self_hosted' => false]);

    $page = visit(route('login'))->resize(375, 812);
    waitFor($page, '@login-submit');

    $layout = $page->script(<<<'JS'
        (() => {
            const email = document.querySelector('#email').getBoundingClientRect();
            const submit = document.querySelector('[data-testid="login-submit"]').getBoundingClientRect();
            const form = document.querySelector('form').getBoundingClientRect();

            return {
                emailHeight: Math.round(email.height),
                submitHeight: Math.round(submit.height),
                submitFullWidth: Math.round(submit.width) === Math.round(email.width),
                formFits: form.left >= 0 && form.right <= window.innerWidth,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS);

    expect($layout)->toBe([
        'emailHeight' => 32,
        'submitHeight' => 32,
        'submitFullWidth' => true,
        'formFits' => true,
        'overflow' => false,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('on a phone the auth content sits in the middle of the screen with the legal line at the bottom', function () {
    $page = visit(route('password.request'))->resize(390, 844);
    waitForState($page, '@auth-logo', 'true');

    $layout = $page->script(<<<'JS'
        (() => {
            const block = document.querySelector('[data-testid="auth-content"]').getBoundingClientRect();
            return { top: Math.round(block.top), bottomGap: Math.round(window.innerHeight - block.bottom) };
        })()
    JS);

    expect(abs($layout['top'] - $layout['bottomGap']))->toBeLessThan(140);
    $page->assertNoJavaScriptErrors();
});
