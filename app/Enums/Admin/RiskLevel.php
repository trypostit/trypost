<?php

declare(strict_types=1);

namespace App\Enums\Admin;

/**
 * How likely a paying account is to leave, from the weight of its churn
 * signals.
 */
enum RiskLevel: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /** The weight from which an account's risk is high. */
    public const int HIGH_SCORE = 4;

    /** The weight from which an account's risk is medium. */
    public const int MEDIUM_SCORE = 2;

    /** The level of a signals' weight, null without one. */
    public static function fromScore(int $score): ?self
    {
        if ($score <= 0) {
            return null;
        }

        if ($score >= self::HIGH_SCORE) {
            return self::High;
        }

        if ($score >= self::MEDIUM_SCORE) {
            return self::Medium;
        }

        return self::Low;
    }
}
