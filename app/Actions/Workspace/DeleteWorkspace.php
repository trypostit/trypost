<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\DeleteOrRestoreStrandedMember;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class DeleteWorkspace
{
    /**
     * Delete a workspace and delete (or restore) stranded members.
     *
     * Returns false when SaaS mode blocks deleting the account's last workspace.
     * The account row is locked so concurrent deletes cannot race past that guard.
     */
    public static function execute(Workspace $workspace): bool
    {
        $account = $workspace->account;
        $accountId = (string) $workspace->account_id;
        $deleted = false;
        $mediaPaths = [];

        DB::transaction(function () use ($workspace, $account, &$deleted, &$mediaPaths): void {
            // Serialize deletes per account so the last-workspace SaaS guard
            // cannot race with a concurrent delete of the sibling workspace.
            if ($account?->id) {
                Account::query()->whereKey($account->id)->lockForUpdate()->first();
            }

            $workspaceCount = Workspace::query()
                ->where('account_id', $workspace->account_id)
                ->count();

            if (! config('trypost.self_hosted') && $workspaceCount <= 1) {
                return;
            }

            User::query()
                ->where('current_workspace_id', $workspace->id)
                ->get()
                ->each(function (User $affected) use ($workspace, $account): void {
                    $fallback = self::fallbackWorkspaceFor($affected, $workspace, $account);

                    $affected->update(['current_workspace_id' => $fallback?->id]);
                });

            self::pruneInvitesForWorkspace($workspace);

            // Capture paths inside the lock so uploads that raced into the
            // transaction are included in post-commit filesystem cleanup.
            $mediaPaths = PurgeWorkspace::execute($workspace);

            if ($account) {
                $mediaPaths = [
                    ...$mediaPaths,
                    ...DeleteOrRestoreStrandedMember::forAccountMembers(
                        $account,
                        $account->owner_id,
                        onlyWithoutAccountWorkspaces: true,
                    ),
                ];
            }

            $deleted = true;
        });

        if (! $deleted) {
            return false;
        }

        DeleteOrphanedMediaFiles::execute($mediaPaths);

        $account?->syncWorkspaceQuantity();

        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch($accountId, null);
        }

        return true;
    }

    private static function fallbackWorkspaceFor(
        User $user,
        Workspace $deleting,
        ?Account $account,
    ): ?Workspace {
        $fallback = $user->workspaces()
            ->where('workspaces.id', '!=', $deleting->id)
            ->where('workspaces.account_id', $deleting->account_id)
            ->first();

        if ($fallback) {
            return $fallback;
        }

        // Use the already-loaded account owner_id — never isAccountOwner(),
        // which can touch the account relation under shouldBeStrict().
        $isOwnerOfDeletingAccount = $account !== null
            && $user->id === $account->owner_id
            && $user->account_id === $deleting->account_id;

        if (! $isOwnerOfDeletingAccount) {
            return null;
        }

        $fallback = Workspace::query()
            ->where('account_id', $deleting->account_id)
            ->where('id', '!=', $deleting->id)
            ->first();

        if ($fallback && ! $user->belongsToWorkspace($fallback)) {
            $fallback->members()->syncWithoutDetaching([
                $user->id => ['role' => Role::Admin->value],
            ]);
        }

        return $fallback;
    }

    private static function pruneInvitesForWorkspace(Workspace $workspace): void
    {
        Invite::query()
            ->where('account_id', $workspace->account_id)
            ->whereNull('accepted_at')
            ->whereJsonContains('workspaces', $workspace->id)
            ->get()
            ->each(function (Invite $invite) use ($workspace): void {
                $remaining = collect(data_get($invite, 'workspaces', []))
                    ->reject(fn (mixed $id): bool => (string) $id === (string) $workspace->id)
                    ->values()
                    ->all();

                if ($remaining === []) {
                    $invite->delete();

                    return;
                }

                $invite->update(['workspaces' => $remaining]);
            });
    }
}
