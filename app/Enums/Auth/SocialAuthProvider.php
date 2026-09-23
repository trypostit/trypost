<?php

declare(strict_types=1);

namespace App\Enums\Auth;

enum SocialAuthProvider: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Oidc = 'oidc';

    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            // Self-hosted providers are named by the operator, so the button
            // can read "Login with <company> SSO" instead of "OIDC".
            self::Oidc => (string) config('trypost.oidc_display_name'),
        };
    }

    public function isEnabled(): bool
    {
        return (bool) config("trypost.{$this->value}_auth_enabled");
    }
}
