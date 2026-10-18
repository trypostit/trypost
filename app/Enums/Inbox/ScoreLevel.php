<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum ScoreLevel: string
{
    case GettingStarted = 'getting_started';
    case FindingYourVoice = 'finding_your_voice';
    case ReplyingRegularly = 'replying_regularly';
    case ConversingConsistently = 'conversing_consistently';
    case CommunityChampion = 'community_champion';

    public static function fromScore(int $score): self
    {
        return match (true) {
            $score <= 20 => self::GettingStarted,
            $score <= 40 => self::FindingYourVoice,
            $score <= 60 => self::ReplyingRegularly,
            $score <= 80 => self::ConversingConsistently,
            default => self::CommunityChampion,
        };
    }
}
