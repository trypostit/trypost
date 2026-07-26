<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\User\EnsurePersonalAccount;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;

class DeleteWorkspace
{
    public static function execute(User $user, Workspace $workspace): void
    {
        User::where('current_workspace_id', $workspace->id)
            ->get()
            ->each(function (User $affected) use ($workspace): void {
                $fallback = $affected->workspaces()
                    ->where('workspaces.id', '!=', $workspace->id)
                    ->first();

                $affected->update(['current_workspace_id' => $fallback?->id]);
            });

        $account = $workspace->account;
        $accountId = (string) $workspace->account_id;

        $workspace->delete();

        if ($account) {
            User::query()
                ->where('account_id', $account->id)
                ->where('id', '!=', $account->owner_id)
                ->whereDoesntHave('workspaces')
                ->get()
                ->each(fn (User $stranded) => EnsurePersonalAccount::execute($stranded));
        }

        $account?->syncWorkspaceQuantity();

        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch($accountId, null);
        }
    }
}
