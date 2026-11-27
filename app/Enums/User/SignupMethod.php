<?php

declare(strict_types=1);

namespace App\Enums\User;

use App\Models\User;

/**
 * How a user signed up: with Google, with GitHub, or with an email and a
 * password.
 */
enum SignupMethod: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Email = 'email';

    public static function of(User $user): self
    {
        if (filled($user->google_id)) {
            return self::Google;
        }

        if (filled($user->github_id)) {
            return self::GitHub;
        }

        return self::Email;
    }

    /** The name the backoffice shows, in English. */
    public function label(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::GitHub => 'GitHub',
            self::Email => 'Email and password',
        };
    }
}
