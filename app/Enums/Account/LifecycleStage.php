<?php

declare(strict_types=1);

namespace App\Enums\Account;

enum LifecycleStage: string
{
    case Churned = 'churned';
    case SignedUp = 'signed_up';
    case Onboarding = 'onboarding';
    case Connected = 'connected';
    case Active = 'active';
    case AtRisk = 'at_risk';
    case Dormant = 'dormant';
}
