<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Laravel\Cashier\Billable;

/**
 * Cashier's Billable, with the owner as the Stripe customer: their name,
 * email and the metadata from SyncsStripeCustomer.
 */
trait BillsOwnerAsStripeCustomer
{
    use Billable;

    public function stripeName(): ?string
    {
        return $this->owner?->name;
    }

    public function stripeEmail(): ?string
    {
        return $this->owner?->email;
    }

    /**
     * @return array<string, string>
     */
    public function stripeMetadata(): array
    {
        return $this->owner?->stripeMetadata() ?? [];
    }
}
