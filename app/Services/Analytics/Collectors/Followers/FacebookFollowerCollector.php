<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class FacebookFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.facebook.graph_api')."/{$account->platform_user_id}",
            ['fields' => 'followers_count'],
            meta: true,
        );

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
