<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Actions\Account\CancelAccountSubscription;
use App\Enums\UserWorkspace\Role as WorkspaceRole;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a user who just signed in through OIDC onto the instance's shared
 * account, so that group membership at the identity provider is all the
 * onboarding a self-hosted team needs - no second invite per person.
 *
 * Mirrors AcceptInvite: move the account, attach the workspaces, then drop the
 * empty personal account that signup leaves behind.
 */
class JoinOidcUserToAccount
{
    public static function execute(User $user, WorkspaceRole $role): bool
    {
        $account = self::targetAccount();

        if (! $account || $user->account_id === $account->id) {
            return false;
        }

        $workspaces = $account->workspaces()->orderBy('created_at')->get();

        // Nothing to join yet - the team has not created a workspace.
        if ($workspaces->isEmpty()) {
            return false;
        }

        $previousAccountId = $user->account_id;

        DB::transaction(function () use ($user, $account, $workspaces, $role): void {
            $user->update(['account_id' => $account->id]);
            $user->refresh();

            foreach ($workspaces as $workspace) {
                $alreadyMember = $workspace->members()
                    ->where('users.id', $user->id)
                    ->exists();

                // Never overwrite an existing pivot role (avoids demoting admins).
                if (! $alreadyMember) {
                    $workspace->members()->attach($user->id, ['role' => $role->value]);
                }
            }

            $user->update(['current_workspace_id' => $workspaces->first()->id]);
            $user->refresh();
        });

        // Drop the personal account shell after commit, the same way invite
        // acceptance does, so Stripe cancellation is not held inside the
        // transaction.
        if ($previousAccountId) {
            $shell = Account::query()
                ->whereKey($previousAccountId)
                ->where('owner_id', $user->id)
                ->whereDoesntHave('workspaces')
                ->first();

            if ($shell && CancelAccountSubscription::execute($shell)) {
                $shell->delete();
            }
        }

        return true;
    }

    /**
     * The account new OIDC users are placed on. Configurable for instances that
     * host more than one team; otherwise the oldest account, which on a
     * self-hosted install is the one the first admin created.
     */
    private static function targetAccount(): ?Account
    {
        $configured = config('trypost.oidc_auto_join_account_id');

        if (filled($configured)) {
            return Account::find($configured);
        }

        return Account::query()->orderBy('created_at')->first();
    }
}
