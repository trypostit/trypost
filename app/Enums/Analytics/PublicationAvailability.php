<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum PublicationAvailability: string
{
    case Available = 'available';
    case Deleted = 'deleted';
    case Unavailable = 'unavailable';

    /**
     * A publication the network deleted keeps its row, but loses its link.
     */
    public function visiblePermalink(?string $permalink): ?string
    {
        return $this === self::Deleted ? null : $permalink;
    }
}
