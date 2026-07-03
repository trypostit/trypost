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
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GitHubController extends Controller
{
    use PreservesUtmParameters;

    public function redirect(Request $request): RedirectResponse
    {
        $this->storeUtmParameters($request);

        return Socialite::driver('github')
            ->scopes(['read:user', 'user:email'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $githubUser = Socialite::driver('github')->user();
        } catch (\Exception) {
            return redirect()->route('login');
        }

        // The signup/login redirect is gated by the `guest` middleware and
        // the connect-from-settings redirect by `auth`, so this is a safe
        // signal for which flow we came from.
        if (Auth::check()) {
            return $this->connectToCurrentUser(Auth::user(), (string) $githubUser->getId());
        }

        $user = User::where('github_id', (string) $githubUser->getId())->first();

        // Linking an existing account by email (or creating an already-verified
        // account) requires GitHub to have confirmed that email — the profile's
        // public email arrives without a verification flag, so we check it
        // directly against the user's emails API.
        if (! $user) {
            if (! $githubUser->getEmail()) {
                return redirect()->route('login')->withErrors([
                    'email' => __('auth.github_email_unavailable'),
                ]);
            }

            if (! $this->providerEmailIsVerified($githubUser)) {
                return redirect()->route('login')
                    ->with('flash.error', __('auth.social_email_unverified', ['provider' => 'GitHub']));
            }

            $user = User::where('email', $githubUser->getEmail())->first();
        }

        if ($user) {
            return $this->loginExistingUser($user, (string) $githubUser->getId());
        }

        return $this->registerNewUser($githubUser);
    }

    private function providerEmailIsVerified(SocialiteUser $githubUser): bool
    {
        $email = (string) $githubUser->getEmail();

        try {
            $emails = Http::withToken($githubUser->token)
                ->acceptJson()
                ->get(config('services.github.api').'/user/emails')
                ->throw()
                ->json();
        } catch (Throwable) {
            return false;
        }

        return collect($emails)->contains(
            fn ($entry): bool => strcasecmp((string) data_get($entry, 'email'), $email) === 0
                && (bool) data_get($entry, 'verified', false),
        );
    }

    private function connectToCurrentUser(User $user, string $githubId): RedirectResponse
    {
        $existing = User::where('github_id', $githubId)
            ->where('id', '!=', $user->id)
            ->first();

        if ($existing) {
            return redirect()->route('app.authentication.edit')
                ->with('flash.error', __('settings.authentication.providers.flash_already_linked', ['provider' => 'GitHub']));
        }

        if ($user->github_id !== $githubId) {
            $user->update(['github_id' => $githubId]);
        }

        return redirect()->route('app.authentication.edit')
            ->with('flash.success', __('settings.authentication.providers.flash_connected', ['provider' => 'GitHub']));
    }

    private function loginExistingUser(User $user, string $githubId): RedirectResponse
    {
        if (! $user->github_id) {
            $user->update(['github_id' => $githubId]);
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        Auth::login($user, remember: true);

        $this->retrieveUtmParameters();

        return redirect()->route('app.home');
    }

    private function registerNewUser(SocialiteUser $githubUser): RedirectResponse
    {
        $utmParameters = $this->retrieveUtmParameters();

        $user = CreateUser::execute([
            'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? explode('@', $githubUser->getEmail())[0],
            'email' => $githubUser->getEmail(),
            'github_id' => (string) $githubUser->getId(),
            'email_verified_at' => now(),
            'registration_ip' => request()->ip(),
        ], $utmParameters);

        event(new Registered($user));

        Auth::login($user, remember: true);

        session()->flash('auth_provider', 'github');

        return redirect()->route('register.success', $utmParameters);
    }
}
