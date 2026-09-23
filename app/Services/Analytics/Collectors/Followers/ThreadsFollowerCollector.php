<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Contracts\Analytics\FollowerCollector;
use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class ThreadsFollowerCollector extends AbstractFollowerCollector implements FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            config('trypost.platforms.threads.graph_api')."/{$account->platform_user_id}/threads_insights",
            ['metric' => 'followers_count'],
            meta: true,
        );

        $metric = collect(data_get($response->json(), 'data', []))
            ->firstWhere('name', 'followers_count');

        return $this->observation($date, data_get($metric, 'total_value.value'));
    }
}
