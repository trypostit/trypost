<?php

declare(strict_types=1);

namespace App\Models\Traits;

use Laravel\Cashier\Billable;
use Stripe\Customer;

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

    /**
     * @return Customer
     */
    public function syncStripeCustomerDetails()
    {
        return $this->updateStripeCustomer([
            'name' => $this->stripeName(),
            'email' => $this->stripeEmail(),
            'metadata' => $this->owner?->stripeMetadataForUpdate() ?? [],
        ]);
    }
}
