<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;

class EnsurePersonalAccount
{
    /**
     * Ensure the user owns a personal account and points at it.
     *
     * Used for invite-redirect / onboarding paths where a non-owner still needs
     * a personal account. Stranded invitees are deleted via
     * DeleteOrRestoreStrandedMember instead.
     */
    public static function execute(User $user): Account
    {
        if ($user->account_id && $user->isAccountOwner()) {
            return $user->account;
        }

        $personalAccount = Account::query()
            ->where('owner_id', $user->id)
            ->when(
                $user->account_id,
                fn ($query) => $query->where('id', '!=', $user->account_id),
            )
            ->first();

        if (! $personalAccount) {
            $personalAccount = Account::create(self::newPersonalAccountAttributes($user));
        }

        $user->update(['account_id' => $personalAccount->id]);

        $currentBelongsToPersonal = $user->current_workspace_id
            && $user->workspaces()
                ->where('workspaces.account_id', $personalAccount->id)
                ->where('workspaces.id', $user->current_workspace_id)
                ->exists();

        if (! $currentBelongsToPersonal) {
            $fallback = $user->workspaces()
                ->where('workspaces.account_id', $personalAccount->id)
                ->first();

            $user->update(['current_workspace_id' => $fallback?->id]);
        }

        return $personalAccount;
    }

    /**
     * @return array<string, mixed>
     */
    private static function newPersonalAccountAttributes(User $user): array
    {
        $attributes = [
            'name' => "{$user->name}'s Account",
            'billing_email' => $user->email,
            'owner_id' => $user->id,
        ];

        // Match CreateUser: when trials don't require a card, seed plan + trial
        // so rehomed members aren't forced through a dead-end onboarding state.
        if (! config('trypost.self_hosted')
            && ! (bool) config('trypost.billing.require_card_for_trial', true)) {
            $attributes['plan_id'] = Plan::where('slug', Slug::Workspace)->value('id');
            $attributes['trial_ends_at'] = now()->addDays((int) config('cashier.trial_days'));
        }

        return $attributes;
    }
}
