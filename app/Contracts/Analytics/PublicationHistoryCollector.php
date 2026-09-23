<?php

declare(strict_types=1);

namespace App\Contracts\Analytics;

use App\Dto\Analytics\PublicationPage;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

interface PublicationHistoryCollector
{
    public function page(
        SocialAccount $account,
        ?string $cursor,
        CarbonImmutable $cutoff,
    ): PublicationPage;
}
