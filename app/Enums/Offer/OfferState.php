<?php

declare(strict_types=1);

namespace App\Enums\Offer;

/**
 * Where an offer stands today, as the admin shows it.
 */
enum OfferState: string
{
    /** It starts later. */
    case Scheduled = 'scheduled';

    /** Started, not ended, with a place left: accounts can claim and redeem it. */
    case Live = 'live';

    /** Started and not ended, but every place is redeemed. */
    case Full = 'full';

    /** Its end has passed. */
    case Ended = 'ended';
}
