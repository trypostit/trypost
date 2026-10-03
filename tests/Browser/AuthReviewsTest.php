<?php

declare(strict_types=1);

test('the login screen shows the customer reviews to a logged out visitor', function () {
    visit(route('login'))
        ->assertVisible('@auth-reviews')
        ->assertVisible('@auth-reviews-g2-link')
        ->assertSee('Loved by people who publish every day')
        ->assertNoJavaScriptErrors();
});

test('every review renders once for readers and once for the marquee loop', function () {
    $page = visit(route('login'));

    $page->assertVisible('@auth-reviews');

    // `naturalWidth` is 0 until each photo has loaded.
    $result = $page->script(<<<'JS'
        (async () => {
            const select = () => [...document.querySelectorAll('[data-testid="auth-reviews"] figure')];
            const loaded = (figure) => {
                const img = figure.querySelector('img');
                return img !== null && img.complete && img.naturalWidth > 0;
            };

            for (let i = 0; i < 200; i++) {
                const current = select();
                if (current.length > 0 && current.every(loaded)) break;
                await new Promise((r) => setTimeout(r, 50));
            }

            const figures = select();

            return {
                total: figures.length,
                hidden: figures.filter((figure) => figure.getAttribute('aria-hidden') === 'true').length,
                rotations: figures.map((figure) => figure.classList.contains('-rotate-1') ? 'left' : 'right').join(','),
                photosLoaded: figures.filter((figure) => figure.querySelector('img')?.naturalWidth > 0).length,
            };
        })();
    JS);

    expect($result['total'])->toBe(10)
        ->and($result['hidden'])->toBe(5)
        ->and($result['photosLoaded'])->toBe(10)
        ->and($result['rotations'])->toBe('left,right,left,right,left,left,right,left,right,left');
});

test('the reviews panel sits beside login and register only', function (string $route, bool $hasPanel) {
    config(['trypost.self_hosted' => false]);

    $page = visit(route($route));

    $page->assertVisible('@auth-logo');

    $hasPanel
        ? $page->assertPresent('@auth-reviews')
        : $page->assertMissing('@auth-reviews');

    $page->assertNoJavaScriptErrors();
})->with([
    ['login', true],
    ['register', true],
    ['password.request', false],
]);

test('the auth column keeps a 16px gutter without overflow on phones', function (string $route) {
    config(['trypost.self_hosted' => false]);

    $page = visit(route($route))->resize(390, 844);
    $page->assertVisible('@auth-logo');

    $layout = $page->script(<<<'JS'
        (() => {
            const email = document.querySelector('#email').getBoundingClientRect();
            return {
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                left: Math.round(email.left),
                right: Math.round(window.innerWidth - email.right),
                height: Math.round(email.height),
            };
        })()
    JS);

    expect($layout)->toBe(['overflow' => false, 'left' => 16, 'right' => 16, 'height' => 32]);
})->with(['login', 'register', 'password.request']);
