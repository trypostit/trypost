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
        $response = $this->get($account, "{$account->mastodon_instance}/api/v1/accounts/{$account->platform_user_id}");

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
