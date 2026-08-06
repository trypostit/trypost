<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Support\BillingCycle;
use Illuminate\Auth\Access\Response;

class AccountPolicy
{
    public function update(User $user, Account $account): bool
    {
        return $user->id === $account->owner_id;
    }

    public function manageBilling(User $user, Account $account): bool
    {
        return $user->id === $account->owner_id;
    }

    /**
     * Authorize using AI features. Requires an active subscription (or trial)
     * and remaining monthly credits. Manual post creation is unaffected — only
     * AI calls are gated by this check.
     */
    public function useAi(User $user, Account $account): Response
    {
        if (config('trypost.self_hosted')) {
            return Response::allow();
        }

        if (! $account->hasAppAccess()) {
            return Response::deny(__('billing.flash.subscription_required'));
        }

        $cycle = BillingCycle::for($account);
        $limit = $cycle->creditAllotment();

        if ($cycle->usedCredits() >= $limit) {
            return Response::deny(__('billing.flash.credits_exhausted', [
                'limit' => (string) $limit,
            ]));
        }

        return Response::allow();
    }

    /**
     * Authorize swapping the account's subscription billing interval. Only the
     * account owner may change billing; per-workspace pricing has no plan tiers
     * to downgrade between, so there are no usage-based restrictions.
     */
    public function swapPlan(User $user, Account $account): Response
    {
        if ($user->id !== $account->owner_id) {
            return Response::deny(__('billing.flash.cannot_manage'));
        }

        return Response::allow();
    }
}
