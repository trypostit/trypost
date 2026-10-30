<?php

declare(strict_types=1);

use App\Models\User;

test('home redirects to login for guests', function () {
    $response = $this->get(route('app.home'));

    $response->assertRedirect(route('login'));
});

test('home redirects to calendar for authenticated users', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('app.home'));

    $response->assertRedirect(route('app.calendar'));
});
