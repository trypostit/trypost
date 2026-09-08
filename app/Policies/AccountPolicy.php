<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
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
     * Authorize using AI features. Requires app access (active subscription or
     * trial). There is no usage ceiling: AI usage is recorded for cost
     * visibility, never metered against the account.
     */
    public function useAi(User $user, Account $account): Response
    {
        if (config('trypost.self_hosted')) {
            return Response::allow();
        }

        if (! $account->hasAppAccess()) {
            return Response::deny(__('billing.flash.subscription_required'));
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
