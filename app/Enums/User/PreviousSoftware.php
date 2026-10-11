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
}
