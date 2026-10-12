<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStripeCustomerMetadata implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $accountId) {}

    public function handle(): void
    {
        $account = Account::find($this->accountId);

        if (! $account?->hasStripeId()) {
            return;
        }

        $account->updateStripeCustomer(['metadata' => $account->stripeMetadata()]);
    }
}
