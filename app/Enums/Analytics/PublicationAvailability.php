<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum PublicationAvailability: string
{
    case Available = 'available';
    case Deleted = 'deleted';
    case Unavailable = 'unavailable';
}
