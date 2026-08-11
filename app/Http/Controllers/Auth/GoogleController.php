<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesAttributionParameters;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    use PreservesAttributionParameters;

    public function redirect(Request $request): RedirectResponse
    {
        $this->storeAttributionParameters($request);

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

        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            return $this->loginExistingUser($user, $googleUser->getId());
        }

        return $this->registerNewUser($googleUser);
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

        $this->retrieveAttributionParameters();

        return redirect()->route('app.home');
    }

    private function registerNewUser(\Laravel\Socialite\Contracts\User $googleUser): RedirectResponse
    {
        $attributionParameters = $this->retrieveAttributionParameters();

        $user = CreateUser::execute([
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'email_verified_at' => now(),
            'registration_ip' => request()->ip(),
        ], $attributionParameters);

        event(new Registered($user));

        Auth::login($user, remember: true);

        session()->flash('auth_provider', 'google');

        return redirect()->route('register.success', $attributionParameters);
    }
}
