<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;

/**
 * Keeps the X subscription and verification type on the account, which decide
 * whether it may publish long posts. Fed by the `/users/me` call the connection
 * verifier makes; a response that leaves a field out keeps the stored value.
 */
class SyncXSubscription
{
    public const string USER_FIELDS = 'subscription_type,verified_type';

    /**
     * @param  array<string, mixed>  $user
     */
    public static function fromUser(SocialAccount $account, array $user): void
    {
        $current = $account->meta ?? [];
        $meta = [
            ...$current,
            ...(array_key_exists('subscription_type', $user) ? ['x_subscription_type' => $user['subscription_type']] : []),
            ...(array_key_exists('verified_type', $user) ? ['x_verified_type' => $user['verified_type']] : []),
        ];

        if ($meta == $current) {
            return;
        }

        $account->forceFill(['meta' => $meta])->saveQuietly();
    }
}
