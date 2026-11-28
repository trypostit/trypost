<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Account;
use Illuminate\Support\Str;

/**
 * How an admin table names an account: by its owner and its plan.
 */
final class AccountSummary
{
    /**
     * @return array{user_id: ?string, name: ?string, email: ?string, plan: ?string}
     */
    public static function of(?Account $account): array
    {
        return [
            'user_id' => $account?->owner?->id,
            'name' => self::displayName($account),
            'email' => $account?->owner?->email,
            'plan' => $account?->plan?->name,
        ];
    }

    /** The owner's name when it holds a letter or a digit (some sign up as "_"), else their email. */
    private static function displayName(?Account $account): ?string
    {
        $name = (string) $account?->owner?->name;

        if (Str::isMatch('/[\pL\pN]/u', $name)) {
            return $name;
        }

        return $account?->owner?->email;
    }
}
