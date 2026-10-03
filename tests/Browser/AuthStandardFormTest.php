<?php

declare(strict_types=1);

function waitForAuthStandardFormTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the login form uses the standard control height and fits a 375px phone', function () {
    config(['trypost.self_hosted' => false]);

    $page = visit(route('login'))->resize(375, 812);
    waitForAuthStandardFormTestId($page, 'login-submit');

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
