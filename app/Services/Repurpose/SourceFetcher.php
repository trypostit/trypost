<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Models\SocialAccount;
use Carbon\CarbonInterface;

interface SourceFetcher
{
    /**
     * Recent media published on the account, newest first, including entries
     * this module will skip. Classification is the caller's job.
     *
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since): array;
}
