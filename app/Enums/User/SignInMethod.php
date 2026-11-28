<?php

declare(strict_types=1);

namespace App\Enums\User;

use App\Models\User;

/**
 * A way a user can sign in: with Google, with GitHub, or with an email and a
 * password. A user may have several (one who signed up with an email and
 * linked Google since has both), so this says what the account can sign in
 * with now, never how it signed up.
 */
enum SignInMethod: string
{
    case Google = 'google';
    case GitHub = 'github';
    case Email = 'email';

    /**
     * Every way the user can sign in now.
     *
     * @return list<self>
     */
    public static function of(User $user): array
    {
        $methods = [];

        if (filled($user->google_id)) {
            $methods[] = self::Google;
        }

        if (filled($user->github_id)) {
            $methods[] = self::GitHub;
        }

        if (filled($user->password)) {
            $methods[] = self::Email;
        }

        return $methods;
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
