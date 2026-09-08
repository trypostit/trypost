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

    $result = $page->script(<<<'JS'
        (() => {
            const figures = [...document.querySelectorAll('[data-testid="auth-reviews"] figure')];

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
