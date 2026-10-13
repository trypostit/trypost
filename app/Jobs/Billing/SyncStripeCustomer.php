<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stripe\Exception\InvalidRequestException;

/**
 * Mirrors the owner's name, email and Stripe metadata onto the account's
 * Stripe customer. Members and accounts without a customer are skipped, and a
 * customer deleted in Stripe is reported once instead of retried.
 */
class SyncStripeCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 30;

    public function __construct(public string $userId) {}

    public function handle(): void
    {
        $user = User::with('account')->find($this->userId);
        $account = $user?->account;

        if (blank($account) || $account->owner_id !== $user->id || ! $account->hasStripeId()) {
            return;
        }

        try {
            $account->syncStripeCustomerDetails();
        } catch (InvalidRequestException $exception) {
            if ($exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }

            report($exception);
        }
    }
}
