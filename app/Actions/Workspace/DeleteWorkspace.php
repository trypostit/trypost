<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\User\EnsurePersonalAccount;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteWorkspace
{
    /**
     * Delete a workspace and rehome stranded members.
     *
     * Returns false when SaaS mode blocks deleting the account's last workspace.
     * The account row is locked so concurrent deletes cannot race past that guard.
     */
    public static function execute(Workspace $workspace): bool
    {
        $account = $workspace->account;
        $accountId = (string) $workspace->account_id;
        $deleted = false;

        // Load media before the locked transaction so filesystem I/O happens after commit.
        $media = $workspace->media()->get();

        DB::transaction(function () use ($workspace, $account, &$deleted): void {
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
                ->each(function (User $affected) use ($workspace): void {
                    $fallback = self::fallbackWorkspaceFor($affected, $workspace);

                    $affected->update(['current_workspace_id' => $fallback?->id]);
                });

            self::pruneInvitesForWorkspace($workspace);

            // Drop media rows inside the transaction without touching storage yet.
            Media::query()
                ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
                ->where('mediable_id', $workspace->id)
                ->delete();

            $workspace->delete();

            if ($account) {
                EnsurePersonalAccount::rehomeAccountMembers(
                    $account,
                    $account->owner_id,
                    onlyWithoutAccountWorkspaces: true,
                );
            }

            $deleted = true;
        });

        if (! $deleted) {
            return false;
        }

        self::deleteOrphanedMediaFiles($media);

        $account?->syncWorkspaceQuantity();

        if (PostHogService::isEnabled()) {
            SyncAccountUsage::dispatch($accountId, null);
        }

        return true;
    }

    /**
     * @param  iterable<int, Media>  $media
     */
    public static function deleteOrphanedMediaFiles(iterable $media): void
    {
        collect($media)->each(function (Media $item): void {
            if (! $item->path) {
                return;
            }

            $otherMediaWithSamePath = Media::query()
                ->where('path', $item->path)
                ->exists();

            if (! $otherMediaWithSamePath) {
                Storage::delete($item->path);
            }
        });
    }

    private static function fallbackWorkspaceFor(User $user, Workspace $deleting): ?Workspace
    {
        $fallback = $user->workspaces()
            ->where('workspaces.id', '!=', $deleting->id)
            ->where('workspaces.account_id', $deleting->account_id)
            ->first();

        if ($fallback) {
            return $fallback;
        }

        if (! $user->isAccountOwner() || $user->account_id !== $deleting->account_id) {
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
                $remaining = collect($invite->workspaces ?? [])
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
