<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;

interface SourceFetcher
{
    /**
     * Recent media published on the account, including entries the caller will
     * skip. Only the endpoints needed for `$formats` are called, so an account
     * watched for Reels alone never pays for the Stories request.
     *
     * @param  array<int, SourceFormat>  $formats
     * @return array<int, SourceMedia>
     */
    public function fetch(SocialAccount $account, ?CarbonInterface $since, array $formats): array;
}
