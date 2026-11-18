<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

/**
 * Whether a published poll still takes votes, as stored with its result. X
 * reports it (`voting_status`); Mastodon says it through `expired`, and Threads
 * through the end of the poll (`expiration_timestamp`). Anything else is
 * stored as unknown (null).
 */
enum PollVotingStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
