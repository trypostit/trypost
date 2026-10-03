<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

class CoverOnlyResolver implements ExternalMediaResolver
{
    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        return [];
    }

    public function callsProvider(): bool
    {
        return false;
    }
}
