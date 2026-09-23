<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Contracts\Analytics\FollowerCollector;
use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            "{$account->platform->instagramGraphBaseUrl()}/{$account->platform_user_id}",
            ['fields' => 'followers_count'],
            meta: true,
        );

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
