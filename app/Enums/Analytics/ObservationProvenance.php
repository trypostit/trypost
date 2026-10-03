<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

enum ObservationProvenance: string
{
    case Actual = 'actual';
    case CarriedForward = 'carried_forward';
}
