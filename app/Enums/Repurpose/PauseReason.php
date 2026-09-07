<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

/**
 * Why a repurpose stopped. NULL means the user paused it themselves — that
 * distinction is what decides whether Resume replays the backlog or starts
 * from now.
 */
enum PauseReason: string
{
    case SourceRemoved = 'source_removed';
    case SourceUnavailable = 'source_unavailable';
    case NoDestinations = 'no_destinations';
}
