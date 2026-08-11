<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\User\CreateUser;
use App\Http\Controllers\Auth\Concerns\PreservesAttributionParameters;
use App\Http\Controllers\Auth\Concerns\PreservesInvite;
use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GitHubController extends Controller
{
    use PreservesAttributionParameters, PreservesInvite;

    public function redirect(Request $request): RedirectResponse
    {
        $this->storeAttributionParameters($request);
        $this->storeInvite($request);

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

        $user = User::where('github_id', (string) $githubUser->getId())
            ->when($githubUser->getEmail(), fn ($query, $email) => $query->orWhere('email', $email))
            ->first();

        if ($user) {
            return $this->loginExistingUser($user, (string) $githubUser->getId());
        }

        if (! $githubUser->getEmail()) {
            return redirect()->route('login')->withErrors([
                'email' => __('auth.github_email_unavailable'),
            ]);
        }

        return $this->registerNewUser($githubUser);
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

        $this->retrieveAttributionParameters();

        if ($invite = Invite::fromId($this->retrieveInvite())) {
            return redirect()->route('app.invites.show', $invite);
        }

        return redirect()->route('app.home');
    }

    private function registerNewUser(\Laravel\Socialite\Contracts\User $githubUser): RedirectResponse
    {
        $attributionParameters = $this->retrieveAttributionParameters();
        $inviteId = $this->retrieveInvite();

        // Same soft gate as the `registration.enabled` middleware on
        // /register: self-hosted requires *some* invite param to reach
        // registration at all. The OAuth redirect route is shared with
        // login (we can't tell them apart before the callback resolves an
        // identity), so this is the earliest point we can enforce it.
        if ((bool) config('trypost.self_hosted') && ! $inviteId) {
            throw new NotFoundHttpException;
        }

        $invite = Invite::fromId($inviteId);

        $user = CreateUser::execute([
            'name' => $githubUser->getName() ?? $githubUser->getNickname() ?? explode('@', $githubUser->getEmail())[0],
            'email' => $githubUser->getEmail(),
            'github_id' => (string) $githubUser->getId(),
            'email_verified_at' => now(),
            'is_invite' => $invite !== null,
            'registration_ip' => request()->ip(),
        ], $attributionParameters);

        event(new Registered($user));

        Auth::login($user, remember: true);

        session()->flash('auth_provider', 'github');

        if ($invite) {
            return redirect()->route('app.invites.show', $invite);
        }

        return redirect()->route('register.success', $attributionParameters);
    }
}
