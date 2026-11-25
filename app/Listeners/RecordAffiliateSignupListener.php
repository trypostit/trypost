<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\Affonso\RecordAffiliateSignup;
use App\Services\Affonso\AffonsoClient;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Auth\Events\Registered;

class RecordAffiliateSignupListener
{
    public function __construct(private AffonsoClient $affonso) {}

    /**
     * Every signup (email, Google, GitHub) fires `Registered` while the request
     * still carries the referral cookie the Affonso pixel set. The lead is
     * recorded on the queue, so Affonso being down never touches the signup.
     */
    public function handle(Registered $event): void
    {
        if (! $this->affonso->isConfigured()) {
            return;
        }

        $clickId = request()->cookie(ConfigureSubscriptionCheckout::AFFILIATE_REFERRAL_KEY);

        if (blank($clickId)) {
            return;
        }

        RecordAffiliateSignup::dispatch((string) $event->user->getAuthIdentifier(), $clickId);
    }
}
