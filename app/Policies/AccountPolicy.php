<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Account;
use App\Models\Plan;
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

    public function swapPlan(User $user, Account $account, Plan $target): Response
    {
        if ($user->id !== $account->owner_id) {
            return Response::deny(__('billing.flash.cannot_manage'));
        }

        if (! $account->subscribed(Account::SUBSCRIPTION_NAME)) {
            return Response::deny(__('billing.flash.subscription_required'));
        }

        $limit = $target->workspace_limit;
        $count = $account->workspaces()->count();

        if ($limit !== null && $count > $limit) {
            return Response::deny(__('billing.flash.too_many_workspaces', [
                'count' => $count,
                'limit' => $limit,
            ]));
        }

        return Response::allow();
    }
}
