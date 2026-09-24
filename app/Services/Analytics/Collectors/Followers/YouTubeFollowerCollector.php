<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class YouTubeFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.youtube.data_api').'/channels',
            ['part' => 'statistics', 'id' => $account->platform_user_id],
        );
        $statistics = data_get($response->json(), 'items.0.statistics');

        if (data_get($statistics, 'hiddenSubscriberCount') === true) {
            return new AccountDailyObservation(
                date: $date,
                followers: null,
                provenance: ObservationProvenance::Actual,
                precision: MetricPrecision::Approximate,
                providerObservedAt: CarbonImmutable::now('UTC'),
                collectedAt: CarbonImmutable::now('UTC'),
            );
        }

        return $this->observation(
            $date,
            data_get($statistics, 'subscriberCount'),
            MetricPrecision::Approximate,
        );
    }
}
