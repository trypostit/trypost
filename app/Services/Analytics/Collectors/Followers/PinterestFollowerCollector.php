<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Contracts\Analytics\FollowerCollector;
use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class PinterestFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get($account, config('trypost.platforms.pinterest.api').'/user_account');

        return $this->observation($date, data_get($response->json(), 'follower_count'));
    }
}
