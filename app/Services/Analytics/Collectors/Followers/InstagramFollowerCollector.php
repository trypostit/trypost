<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\SocialAccount\GatedFeature;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        AnalyticsCollectionException::unlessPermitted($account, GatedFeature::Followers);

        $response = $this->get(
            $account,
            "{$account->platform->graphApiUrl()}/{$account->platform_user_id}",
            ['fields' => 'followers_count'],
            meta: true,
        );

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
