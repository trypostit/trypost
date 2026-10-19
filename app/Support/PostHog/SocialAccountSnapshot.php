<?php

declare(strict_types=1);

namespace App\Support\PostHog;

use App\Models\SocialAccount;

final class SocialAccountSnapshot
{
    public const REASON_REMOVED = 'removed';

    public const SOURCE_TELEGRAM = 'telegram';

    /**
     * The properties of a channel that may be gone by the time the event is sent.
     *
     * @return array{social_account_id: string, platform: string, network: string, workspace_id: string, reason: string, error_type?: string}
     */
    public static function of(SocialAccount $socialAccount, string $reason, bool $withErrorType = false): array
    {
        return [
            'social_account_id' => (string) $socialAccount->id,
            'platform' => $socialAccount->platform->value,
            'network' => $socialAccount->platform->network(),
            'workspace_id' => (string) $socialAccount->workspace_id,
            'reason' => $reason,
            ...($withErrorType ? ['error_type' => $reason] : []),
        ];
    }
}
