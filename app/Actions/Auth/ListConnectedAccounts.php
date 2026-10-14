<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\Auth\SocialAuthProvider;
use App\Models\User;

class ListConnectedAccounts
{
    /**
     * Every sign-in provider, whether the user is linked to it and whether
     * unlinking it still leaves them a way to sign in.
     *
     * @return list<array{provider: string, label: string, connected: bool, can_disconnect: bool}>
     */
    public static function execute(User $user): array
    {
        return collect(SocialAuthProvider::cases())->map(fn (SocialAuthProvider $provider): array => [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'connected' => $user->isConnectedTo($provider),
            'can_disconnect' => $user->isConnectedTo($provider) && $user->canDisconnectFrom($provider),
        ])->values()->all();
    }
}
