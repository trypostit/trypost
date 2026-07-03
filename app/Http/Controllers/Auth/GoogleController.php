<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesUtmParameters;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    use PreservesUtmParameters;

    public function redirect(Request $request): RedirectResponse
    {
        $this->storeUtmParameters($request);

        return Socialite::driver('google-auth')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google-auth')->user();
        } catch (\Exception) {
            return redirect()->route('login');
        }

        // The signup/login redirect is gated by the `guest` middleware and
        // the connect-from-settings redirect by `auth`, so this is a safe
        // signal for which flow we came from.
        if (Auth::check()) {
            return $this->connectToCurrentUser(Auth::user(), $googleUser->getId());
        }

        $user = User::where('google_id', $googleUser->getId())->first();

        // Linking an existing account by email (or creating an already-verified
        // account) requires the PROVIDER to have confirmed that email —
        // otherwise a Google account carrying someone else's unconfirmed email
        // would become an account takeover.
        if (! $user) {
            if (! $this->providerEmailIsVerified($googleUser)) {
                return redirect()->route('login')
                    ->with('flash.error', __('auth.social_email_unverified', ['provider' => 'Google']));
            }

            $user = User::where('email', $googleUser->getEmail())->first();
        }

        if ($user) {
            return $this->loginExistingUser($user, $googleUser->getId());
        }

        return $this->registerNewUser($googleUser);
    }

    /**
     * OIDC `email_verified` claim from Google's userinfo; absent = don't trust.
     */
    private function providerEmailIsVerified(SocialiteUser $googleUser): bool
    {
        return (bool) data_get($googleUser->user, 'email_verified', false);
    }

    private function connectToCurrentUser(User $user, string $googleId): RedirectResponse
    {
        $existing = User::where('google_id', $googleId)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            return redirect()->route('app.authentication.edit')
                ->with('flash.error', __('settings.authentication.providers.flash_already_linked', ['provider' => 'Google']));
        }

        if ($user->google_id !== $googleId) {
            $user->update(['google_id' => $googleId]);
        }

        return redirect()->route('app.authentication.edit')
            ->with('flash.success', __('settings.authentication.providers.flash_connected', ['provider' => 'Google']));
    }

    private function loginExistingUser(User $user, string $googleId): RedirectResponse
    {
        if (! $user->google_id) {
            $user->update(['google_id' => $googleId]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);

        $this->retrieveUtmParameters();

        return redirect()->route('app.home');
    }

    private function registerNewUser(SocialiteUser $googleUser): RedirectResponse
    {
        $utmParameters = $this->retrieveUtmParameters();

        $user = CreateUser::execute([
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'email_verified_at' => now(),
            'registration_ip' => request()->ip(),
        ], $utmParameters);

        event(new Registered($user));

        Auth::login($user, remember: true);

        session()->flash('auth_provider', 'google');

        return redirect()->route('register.success', $utmParameters);
    }
}
