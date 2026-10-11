<?php

declare(strict_types=1);

namespace App\Enums\User;

enum PreviousSoftware: string
{
    case Buffer = 'buffer';
    case Hootsuite = 'hootsuite';
    case Later = 'later';
    case Metricool = 'metricool';
    case Typefully = 'typefully';
    case Hypefury = 'hypefury';
    case Publer = 'publer';
    case Postiz = 'postiz';
    case SproutSocial = 'sprout_social';
    case SocialBee = 'socialbee';
    case None = 'none';
    case Other = 'other';

    /**
     * Brand names read the same in every language, so they skip i18n.
     * Null for the options that are translated (none, other).
     */
    public function brandName(): ?string
    {
        return match ($this) {
            self::Buffer => 'Buffer',
            self::Hootsuite => 'Hootsuite',
            self::Later => 'Later',
            self::Metricool => 'Metricool',
            self::Typefully => 'Typefully',
            self::Hypefury => 'Hypefury',
            self::Publer => 'Publer',
            self::Postiz => 'Postiz',
            self::SproutSocial => 'Sprout Social',
            self::SocialBee => 'SocialBee',
            self::None, self::Other => null,
        };
    }
}
