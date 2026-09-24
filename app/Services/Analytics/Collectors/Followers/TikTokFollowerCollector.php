<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class TikTokFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.tiktok.api').'/user/info/',
            ['fields' => 'follower_count'],
        );

        return $this->observation($date, data_get($response->json(), 'data.user.follower_count'));
    }
}
