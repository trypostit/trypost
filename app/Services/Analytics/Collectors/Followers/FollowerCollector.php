<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Followers;

use App\Dto\Analytics\AccountDailyObservation;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

interface FollowerCollector
{
    public function collect(SocialAccount $account, CarbonImmutable $date): AccountDailyObservation;
}
