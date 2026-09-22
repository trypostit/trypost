<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;
}
