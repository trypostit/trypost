<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class InstagramFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        AnalyticsCollectionException::unlessGranted($account, ...$account->platform === Platform::InstagramFacebook
            ? ['instagram_basic', 'pages_read_engagement']
            : ['instagram_business_basic']);

        $response = $this->get(
            $account,
            "{$account->platform->graphApiUrl()}/{$account->platform_user_id}",
            ['fields' => 'followers_count'],
            meta: true,
        );

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
