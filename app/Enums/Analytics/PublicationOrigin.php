<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum PublicationOrigin: string
{
    case TryPost = 'trypost';
    case External = 'external';
}
