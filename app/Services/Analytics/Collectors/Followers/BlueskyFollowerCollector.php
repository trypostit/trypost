<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;

class BlueskyFollowerCollector extends AbstractFollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation
    {
        $response = $this->get(
            $account,
            BlueskyLexicon::xrpc(config('trypost.platforms.bluesky.public_appview'), BlueskyLexicon::GET_PROFILE),
            ['actor' => $account->platform_user_id],
        );

        return $this->observation($date, data_get($response->json(), 'followersCount'));
    }
}
