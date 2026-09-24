<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class XFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.x.api')."/users/{$account->platform_user_id}",
            ['user.fields' => 'public_metrics'],
        );

        return $this->observation($date, data_get($response->json(), 'data.public_metrics.followers_count'));
    }
}
