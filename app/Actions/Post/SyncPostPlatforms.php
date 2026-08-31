<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;

class SyncPostPlatforms
{
    /**
     * Ensure the post has a post_platform row for every currently-active social
     * account in its workspace. New rows are created with enabled=false so the
     * user can opt into the additional accounts via the Schedule tab without
     * losing existing toggle state.
     */
    public static function execute(Post $post): void
    {
        $workspace = $post->workspace;

        $existingAccountIds = $post->postPlatforms()
            ->whereNull('google_business_profile_location_id')
            ->pluck('social_account_id')
            ->filter();

        $existingGoogleLocationIds = $post->postPlatforms()
            ->whereNotNull('google_business_profile_location_id')
            ->pluck('google_business_profile_location_id')
            ->filter();

        $missingAccounts = $workspace->socialAccounts()
            ->active()
            ->with(['googleBusinessProfileLocations' => fn ($query) => $query->where('is_selected', true)])
            ->get();

        foreach ($missingAccounts as $account) {
            if ($account->platform === Platform::GoogleBusinessProfile) {
                foreach ($account->googleBusinessProfileLocations->whereNotIn('id', $existingGoogleLocationIds) as $location) {
                    $post->postPlatforms()->create([
                        'social_account_id' => $account->id,
                        'google_business_profile_location_id' => $location->id,
                        'platform' => $account->platform->value,
                        'platform_name' => $location->title,
                        'platform_username' => $location->store_code,
                        'platform_avatar' => $account->getRawOriginal('avatar_url'),
                        'content_type' => ContentType::defaultFor($account->platform),
                        'status' => PostPlatformStatus::Pending,
                        'enabled' => false,
                    ]);
                }

                continue;
            }

            if ($existingAccountIds->contains($account->id)) {
                continue;
            }

            $post->postPlatforms()->create([
                'social_account_id' => $account->id,
                'platform' => $account->platform->value,
                'platform_name' => $account->accountDisplayName(),
                'platform_username' => $account->username,
                'platform_avatar' => $account->getRawOriginal('avatar_url'),
                'content_type' => ContentType::defaultFor($account->platform),
                'status' => PostPlatformStatus::Pending,
                'enabled' => false,
            ]);
        }
    }
}
