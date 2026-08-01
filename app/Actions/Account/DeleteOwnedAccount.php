<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

class DeleteOwnedAccount
{
    /**
     * Cancel Stripe, purge workspaces, then delete an account the user owns.
     * Prefer calling outside a held DB lock.
     *
     * @return list<string>|null media paths when deleted; null when skipped or Stripe failed
     */
    public static function execute(User $user, string $accountId): ?array
    {
        $account = Account::query()
            ->whereKey($accountId)
            ->where('owner_id', $user->id)
            ->first();

        if (! $account) {
            return null;
        }

        if (! CancelAccountSubscription::execute($account)) {
            return null;
        }

        $mediaPaths = [];

        Workspace::query()
            ->where('account_id', $account->id)
            ->get()
            ->each(function (Workspace $workspace) use (&$mediaPaths): void {
                $mediaPaths = [
                    ...$mediaPaths,
                    ...PurgeWorkspace::execute($workspace),
                ];
            });

        if ($user->account_id === $account->id) {
            $user->update(['account_id' => null]);
        }

        $account->delete();

        return $mediaPaths;
    }
}
