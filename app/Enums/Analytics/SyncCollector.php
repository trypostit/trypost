<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum SyncCollector: string
{
    case PublicationBackfill = 'publication_backfill';
    case PublicationDiscovery = 'publication_discovery';
}
