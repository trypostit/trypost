<?php

declare(strict_types=1);

namespace App\Enums\Admin;

/**
 * What says a paying account may leave, as People → Insights weighs it.
 */
enum ChurnSignal: string
{
    case Dormant = 'dormant';
    case AtRisk = 'at_risk';
    case NeverPosted = 'never_posted';
    case NoChannels = 'no_channels';
    case Cancelling = 'cancelling';
    case PastDue = 'past_due';
    case ChannelsBroken = 'channels_broken';
    case PostingDropped = 'posting_dropped';
    case Failing = 'failing';

    /** How much the signal weighs: money already leaving counts most, a slowdown least. */
    public function weight(): int
    {
        return match ($this) {
            self::Cancelling, self::PastDue => 3,
            self::Dormant, self::NeverPosted, self::NoChannels, self::ChannelsBroken => 2,
            self::AtRisk, self::PostingDropped, self::Failing => 1,
        };
    }
}
