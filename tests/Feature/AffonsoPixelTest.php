<?php

declare(strict_types=1);

test('every page loads the affonso pixel when an id is set', function () {
    config([
        'services.affonso.id' => 'test-affonso-id',
        'services.affonso.cookie_duration' => 30,
    ]);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('src="https://cdn.affonso.io/js/pixel.min.js"', false)
        ->assertSee('data-affonso="test-affonso-id"', false)
        ->assertSee('data-cookie_duration="30"', false);
});

test('pages skip the affonso pixel without an id', function () {
    config(['services.affonso.id' => null]);

    $this->get(route('login'))
        ->assertOk()
        ->assertDontSee('data-affonso', false);
});
