<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Services\Social\LinkedInOrganizations;
use Carbon\CarbonInterface;

/**
 * Keeps a LinkedIn Page channel's stored name, vanity name and logo within
 * LinkedIn's data storage limit for organization profile data: 8 weeks for an
 * organization that authenticated into the app. A Page comes due after 7
 * weeks and is read again from LinkedIn; one that cannot be read (its token is
 * dead, or the member no longer administers it) loses the stored profile once
 * it is 8 weeks old, and shows the network's name until it is reconnected.
 *
 * Member profiles (LinkedIn personal channels) are left alone: the
 * authenticated member's basic profile has no storage limit, and LinkedIn's
 * API terms forbid refreshing profile data on an automated schedule.
 *
 * @see https://learn.microsoft.com/en-us/linkedin/marketing/data-storage-requirements
 */
class RefreshLinkedInPageProfile
{
    public const int REFRESH_AFTER_WEEKS = 7;

    public const int KEEP_FOR_WEEKS = 8;

    public static function execute(SocialAccount $account): void
    {
        if ($account->platform !== Platform::LinkedInPage) {
            return;
        }

        if ($account->status === Status::Connected && self::refresh($account)) {
            return;
        }

        if (self::storedSince($account)->lte(now()->subWeeks(self::KEEP_FOR_WEEKS))) {
            $account->forceFill([
                'display_name' => null,
                'username' => null,
                'avatar_url' => null,
            ])->saveQuietly();
        }
    }

    private static function refresh(SocialAccount $account): bool
    {
        $organization = collect(LinkedInOrganizations::administeredBy($account->access_token) ?? [])
            ->first(fn (array $organization): bool => (string) data_get($organization, 'id') === (string) $account->platform_user_id);

        if ($organization === null) {
            return false;
        }

        $account->forceFill([
            'display_name' => data_get($organization, 'name'),
            'username' => data_get($organization, 'vanity_name'),
            'avatar_url' => uploadFromUrl(data_get($organization, 'logo')),
            'profile_refreshed_at' => now(),
        ])->saveQuietly();

        return true;
    }

    private static function storedSince(SocialAccount $account): CarbonInterface
    {
        return $account->profile_refreshed_at ?? $account->created_at;
    }
}
