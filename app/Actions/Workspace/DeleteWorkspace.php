<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\User\SettleStrandedMember;
use App\Actions\User\StrandedSettlement;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class DeleteWorkspace
{
    /**
     * Delete a workspace and settle stranded members (delete invitees who
     * lost their last membership on the account).
     *
     * Returns false when SaaS mode blocks deleting the account's last workspace.
     * The account row is locked so concurrent deletes cannot race past that guard.
     */
    public static function execute(Workspace $workspace): bool
    {
        $account = $workspace->account;
        $accountId = (string) $workspace->account_id;
        $deleted = false;
        $settlement = StrandedSettlement::none();

        DB::transaction(function () use ($workspace, $account, &$deleted, &$settlement): void {
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

            ReassignCurrentWorkspace::awayFromWorkspace(
                workspace: $workspace,
                attachOwnerFallback: true,
                account: $account,
            );

            self::pruneInvitesForWorkspace($workspace);

            // Capture paths inside the lock so uploads that raced into the
            // transaction are included in post-commit filesystem cleanup.
            $mediaPaths = PurgeWorkspace::execute($workspace);

            $settlement = new StrandedSettlement(mediaPaths: $mediaPaths);

            if ($account) {
                $settlement = $settlement->merge(
                    SettleStrandedMember::forAccountMembers(
                        $account,
                        exceptUserId: $account->owner_id,
                        onlyWithoutMemberships: true,
                    ),
                );
            }

            $deleted = true;
        });

        if (! $deleted) {
            return false;
        }

        $settlement->flush();

        $account?->syncWorkspaceQuantity();

        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch($accountId, null);
        }

        return true;
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
