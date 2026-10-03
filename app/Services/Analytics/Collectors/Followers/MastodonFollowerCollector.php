<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class MastodonFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $instance = rtrim((string) data_get(
            $account->meta,
            'instance',
            config('trypost.platforms.mastodon.default_instance'),
        ), '/');
        $response = $this->get($account, "{$instance}/api/v1/accounts/{$account->platform_user_id}");

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
