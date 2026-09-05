<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;

interface SourceFetcher
{
    /**
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array;
}
