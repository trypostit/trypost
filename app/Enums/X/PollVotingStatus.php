<?php

declare(strict_types=1);

namespace App\Enums\X;

/**
 * The poll `voting_status` values X reports; anything else is stored as unknown (null).
 */
enum PollVotingStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
