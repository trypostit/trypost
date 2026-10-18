<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Dto\Analytics\DiscoveredPublication;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;

/**
 * A collector that can read one of the channel's publications by its id,
 * for a comment that arrives before the analytics discovery listed its post.
 */
interface FindsPublication
{
    /**
     * @throws AnalyticsCollectionException
     */
    public function find(SocialAccount $account, string $remoteId): ?DiscoveredPublication;
}
