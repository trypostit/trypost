<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\AcceptInviteController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\GitHubController;
use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/invites/{invite}', [AcceptInviteController::class, 'show'])->name('app.invites.show');

Route::middleware(['guest'])->group(function () {
    Route::middleware('registration.enabled')->group(function () {
        // The page itself stays reachable with password sign-in off - it is
        // where the provider buttons live - but creating an account with a
        // password it could never be used with does not.
        Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
        Route::post('/register', [RegisteredUserController::class, 'store'])
            ->middleware('password.login.enabled')
            ->name('register.store');
    });

    // The login page itself stays reachable with password sign-in switched
    // off - it is where the provider buttons live.
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');

    Route::middleware('password.login.enabled')->group(function () {
        Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');

        Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');

        Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
        Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
    });

    Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/github/redirect', [GitHubController::class, 'redirect'])->name('auth.github.redirect');
    // Unauthenticated and reachable by anyone, and each call makes the server
    // talk to the identity provider. Throttled like the other public auth
    // endpoints so it cannot be used to hammer the provider through us.
    Route::get('/auth/oidc/redirect', [OidcController::class, 'redirect'])
        ->middleware('throttle:30,1')
        ->name('auth.oidc.redirect');
});

// Callbacks must be reachable by both guests (signup/login) and authenticated
// users (connect-from-settings). The redirect routes that initiate the OAuth
// round-trip enforce the right middleware, so the callback can safely branch
// on `Auth::check()` to dispatch to the matching flow.
Route::get('/auth/google/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
Route::get('/auth/github/callback', [GitHubController::class, 'callback'])->name('auth.github.callback');
Route::get('/auth/oidc/callback', [OidcController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('auth.oidc.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/verify-email', EmailVerificationPromptController::class)->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::post('/invites/{invite}/accept', [AcceptInviteController::class, 'accept'])->name('app.invites.accept');
    Route::post('/invites/{invite}/decline', [AcceptInviteController::class, 'decline'])->name('app.invites.decline');
});
