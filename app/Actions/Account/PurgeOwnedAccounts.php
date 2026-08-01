<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Actions\Workspace\PurgeWorkspace;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

class PurgeOwnedAccounts
{
    /**
     * Tear down every account the user owns (optionally skipping one), including
     * their workspaces. Returns media paths for post-commit file cleanup.
     *
     * When $onlyWithWorkspaces is true, empty account shells are left for
     * {@see DeleteEmptyOwnedAccounts} so Stripe can be canceled outside a lock.
     *
     * @return list<string>
     */
    public static function execute(
        User $user,
        ?Account $except = null,
        bool $onlyWithWorkspaces = false,
    ): array {
        $mediaPaths = [];

        Account::query()
            ->where('owner_id', $user->id)
            ->when(
                $except,
                fn ($query) => $query->where('id', '!=', $except->id),
            )
            ->when(
                $onlyWithWorkspaces,
                fn ($query) => $query->whereHas('workspaces'),
            )
            ->get()
            ->each(function (Account $owned) use ($user, &$mediaPaths): void {
                Workspace::query()
                    ->where('account_id', $owned->id)
                    ->get()
                    ->each(function (Workspace $workspace) use (&$mediaPaths): void {
                        $mediaPaths = [
                            ...$mediaPaths,
                            ...PurgeWorkspace::execute($workspace),
                        ];
                    });

                if ($user->account_id === $owned->id) {
                    $user->update(['account_id' => null]);
                }

                $owned->delete();
            });

        return $mediaPaths;
    }
}
