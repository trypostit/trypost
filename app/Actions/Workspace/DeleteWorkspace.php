<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\User\EnsurePersonalAccount;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class DeleteWorkspace
{
    public static function execute(Workspace $workspace): void
    {
        $account = $workspace->account;
        $accountId = (string) $workspace->account_id;

        DB::transaction(function () use ($workspace, $account): void {
            User::where('current_workspace_id', $workspace->id)
                ->get()
                ->each(function (User $affected) use ($workspace): void {
                    $fallback = $affected->workspaces()
                        ->where('workspaces.id', '!=', $workspace->id)
                        ->where('workspaces.account_id', $workspace->account_id)
                        ->first();

                    $affected->update(['current_workspace_id' => $fallback?->id]);
                });

            $workspace->delete();

            if ($account) {
                EnsurePersonalAccount::rehomeAccountMembers(
                    $account,
                    $account->owner_id,
                    onlyWithoutAccountWorkspaces: true,
                );
            }
        });

        $account?->syncWorkspaceQuantity();

        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch($accountId, null);
        }
    }
}
