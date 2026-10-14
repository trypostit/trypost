<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

test('sends verification notification', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('app.home'));

    Notification::assertSentTo($user, VerifyEmail::class);
});

test('does not send verification notification if email is verified', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('verification.send'))
        ->assertRedirect(route('app.calendar', absolute: false));

    Notification::assertNothingSent();
});

test('resending from the profile confirms with a toast instead of a line in the form', function () {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->from(route('app.profile.edit'))
        ->post(route('verification.send'))
        ->assertRedirect(route('app.profile.edit'))
        ->assertSessionHas('flash.success', __('settings.profile.verification_sent'))
        ->assertSessionHas('status', 'verification-link-sent');

    $this->actingAs($user)
        ->get(route('app.profile.edit'))
        ->assertInertia(fn ($page) => $page->missing('status'));
});
