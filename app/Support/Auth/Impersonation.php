<?php

declare(strict_types=1);

namespace App\Support\Auth;

final class Impersonation
{
    /**
     * Session key holding the id of the admin who is viewing the
     * app as another user. Present only while impersonating.
     */
    public const string SESSION_KEY = 'impersonator_id';

    public static function isActive(): bool
    {
        return filled(self::impersonatorId());
    }

    public static function impersonatorId(): ?string
    {
        if (! app()->bound('session')) {
            return null;
        }

        $impersonatorId = session()->get(self::SESSION_KEY);

        if (! is_string($impersonatorId)) {
            return null;
        }

        return $impersonatorId;
    }
}
