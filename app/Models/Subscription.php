<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;

    public function endedBeforeFirstCharge(): bool
    {
        return filled($this->ends_at)
            && filled($this->trial_ends_at)
            && $this->ends_at->lessThanOrEqualTo($this->trial_ends_at);
    }
}
