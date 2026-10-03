<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Dto\RemoteFile;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

interface ExternalMediaResolver
{
    /**
     * The publication's files in network order. Empty when the network exposes none.
     *
     * @return list<RemoteFile>
     */
    public function files(SocialAccount $account, AnalyticsPublication $publication): array;

    /**
     * Whether resolving files spends a request against the network's API.
     */
    public function callsProvider(): bool;
}
