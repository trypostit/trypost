<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Enums\Auth\SocialAuthProvider;
use App\Http\Controllers\App\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;

class ConnectedAccountController extends Controller
{
    public function connect(string $provider): RedirectResponse
    {
        $socialProvider = SocialAuthProvider::tryFrom($provider);

        abort_unless($socialProvider?->isEnabled(), 404);

        return match ($socialProvider) {
            SocialAuthProvider::Google => Socialite::driver('google-auth')->redirect(),
            SocialAuthProvider::GitHub => Socialite::driver('github')->scopes(['read:user', 'user:email'])->redirect(),
            SocialAuthProvider::Oidc => Socialite::driver('oidc')->redirect(),
        };
    }

    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $socialProvider = SocialAuthProvider::tryFrom($provider);

        abort_unless($socialProvider !== null, 404);

        $user = $request->user();

        if (! $user->isConnectedTo($socialProvider)) {
            return back();
        }

        if (! $user->canDisconnectFrom($socialProvider)) {
            return back()->with('flash.error', __('settings.profile.providers.flash_cannot_disconnect'));
        }

        $user->update(["{$socialProvider->value}_id" => null]);

        return back()->with('flash.success', __('settings.profile.providers.flash_disconnected', [
            'provider' => $socialProvider->label(),
        ]));
    }
}
