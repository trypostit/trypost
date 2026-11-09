<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use App\Services\Social\Mastodon\MastodonApi;
use Carbon\CarbonImmutable;

class MastodonFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get($account, MastodonApi::url($account->mastodon_instance, "accounts/{$account->platform_user_id}"));

        return $this->observation($date, data_get($response->json(), 'followers_count'));
    }
}
