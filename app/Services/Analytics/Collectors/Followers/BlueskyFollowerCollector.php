<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Contracts\Analytics\FollowerCollector;
use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class BlueskyFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.bluesky.public_appview').'/xrpc/app.bsky.actor.getProfile',
            ['actor' => $account->platform_user_id],
        );

        return $this->observation($date, data_get($response->json(), 'followersCount'));
    }
}
