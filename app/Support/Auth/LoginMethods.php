<?php

declare(strict_types=1);

namespace App\Support\Auth;

use App\Enums\Auth\SocialAuthProvider;

/**
 * Which ways into the application are open. Instances that put an identity
 * provider in front usually want the local password form gone, so the only way
 * in is the one their policies actually cover.
 */
class LoginMethods
{
    /**
     * Whether email and password sign-in is available.
     *
     * Switching it off is ignored while no other provider is configured: an
     * instance must never be able to lock everybody out through a single
     * environment variable.
     */
    public static function passwordEnabled(): bool
    {
        if ((bool) config('trypost.password_login_enabled')) {
            return true;
        }

        return ! self::anySocialEnabled();
    }

    public static function anySocialEnabled(): bool
    {
        foreach (SocialAuthProvider::cases() as $provider) {
            if ($provider->isEnabled()) {
                return true;
            }
        }

        return false;
    }
}
