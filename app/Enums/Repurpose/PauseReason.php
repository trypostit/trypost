<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

enum PauseReason: string
{
    case SourceRemoved = 'source_removed';
    case SourceUnavailable = 'source_unavailable';
    case NoDestinations = 'no_destinations';
}
