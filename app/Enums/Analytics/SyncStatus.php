<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Complete = 'complete';
    case Partial = 'partial';
    case ProviderLimited = 'provider_limited';
    case Failed = 'failed';
}
