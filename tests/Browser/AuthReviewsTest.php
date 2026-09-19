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
