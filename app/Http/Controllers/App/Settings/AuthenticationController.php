<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Settings;

use App\Http\Controllers\App\Controller;
use App\Http\Requests\App\Settings\AuthenticationPasswordRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;

class AuthenticationController extends Controller
{
    private const array PROVIDERS = ['google', 'github'];

    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('settings/profile/Authentication', [
            'sessions' => $this->getSessions($request),
            'hasPassword' => (bool) $user->password,
            'connectedAccounts' => $this->getConnectedAccounts($user),
        ]);
    }

    public function updatePassword(AuthenticationPasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        // Changing the password drops the other sessions (keeps the current
        // one): if it was changed over a suspected compromise, nobody else
        // stays logged in.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return back()->with('flash.success', __('settings.flash.password_updated'));
    }

    public function destroyOtherSessions(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->password) {
            $request->validate([
                'password' => ['required', 'string', 'current_password'],
            ]);
        } else {
            $request->validate([
                'email_confirmation' => ['required', 'string', Rule::in([$user->email])],
            ], [
                'email_confirmation.in' => __('settings.authentication.sessions.email_mismatch'),
            ]);
        }

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with('flash.success', __('settings.authentication.sessions.flash_logged_out'));
    }

    public function connectProvider(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return match ($provider) {
            'google' => Socialite::driver('google-auth')->redirect(),
            'github' => Socialite::driver('github')->scopes(['read:user', 'user:email'])->redirect(),
        };
    }

    public function disconnectProvider(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        $user = $request->user();
        $column = "{$provider}_id";

        if (! $user->{$column}) {
            return back();
        }

        if (! $this->canDisconnect($user, $provider)) {
            return back()->with('flash.error', __('settings.authentication.providers.flash_cannot_disconnect'));
        }

        $user->update([$column => null]);

        return back()->with('flash.success', __('settings.authentication.providers.flash_disconnected', [
            'provider' => ucfirst($provider),
        ]));
    }

    /**
     * @return array<int, array{id: string, ip_address: string|null, user_agent: string|null, last_active: string, is_current: bool}>
     */
    private function getSessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return collect(
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->orderByDesc('last_activity')
                ->get()
        )->map(fn ($session) => [
            'id' => $session->id,
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'last_active' => Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
            'is_current' => $session->id === $request->session()->getId(),
        ])->values()->all();
    }

    /**
     * @return array<int, array{provider: string, label: string, connected: bool, can_disconnect: bool}>
     */
    private function getConnectedAccounts(User $user): array
    {
        $labels = [
            'google' => 'Google',
            'github' => 'GitHub',
        ];

        return collect(self::PROVIDERS)->map(fn (string $provider) => [
            'provider' => $provider,
            'label' => $labels[$provider],
            'connected' => (bool) $user->{"{$provider}_id"},
            'can_disconnect' => $user->{"{$provider}_id"} && $this->canDisconnect($user, $provider),
        ])->values()->all();
    }

    private function canDisconnect(User $user, string $provider): bool
    {
        $remainingMethods = 0;

        if ($user->password) {
            $remainingMethods++;
        }

        foreach (self::PROVIDERS as $other) {
            if ($other === $provider) {
                continue;
            }

            if ($user->{"{$other}_id"}) {
                $remainingMethods++;
            }
        }

        return $remainingMethods > 0;
    }
}
