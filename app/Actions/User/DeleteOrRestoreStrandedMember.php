<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Media\DeleteWorkspaceMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Token;

class DeleteOrRestoreStrandedMember
{
    /**
     * After losing memberships on a shared account: restore a personal account
     * that still has workspaces, otherwise delete the user (and empty orphans).
     *
     * When $forceDelete is true (owner account teardown), always delete the
     * member — including any leftover personal accounts/workspaces.
     *
     * Returns media storage paths that should be flushed with
     * DeleteOrphanedMediaFiles after the surrounding DB transaction commits.
     *
     * @return list<string>
     */
    public static function execute(User $user, Account $leavingAccount, bool $forceDelete = false): array
    {
        return DB::transaction(function () use ($user, $leavingAccount, $forceDelete): array {
            if ($user->id === $leavingAccount->owner_id) {
                return [];
            }

            if ($user->workspaces()
                ->where('workspaces.account_id', $leavingAccount->id)
                ->exists()
            ) {
                return [];
            }

            if (! $forceDelete) {
                $personalAccount = Account::query()
                    ->where('owner_id', $user->id)
                    ->where('id', '!=', $leavingAccount->id)
                    ->whereHas('workspaces')
                    ->first();

                if ($personalAccount) {
                    $fallback = $user->workspaces()
                        ->where('workspaces.account_id', $personalAccount->id)
                        ->first();

                    $user->update([
                        'account_id' => $personalAccount->id,
                        'current_workspace_id' => $fallback?->id,
                    ]);

                    return [];
                }
            }

            $mediaPaths = $forceDelete
                ? self::purgeOwnedAccounts($user, $leavingAccount)
                : [];

            if (! $forceDelete) {
                self::deleteEmptyPersonalAccounts($user, $leavingAccount);
            }

            $mediaPaths = [
                ...$mediaPaths,
                ...self::purgeUserMediaRecords($user),
            ];
            self::revokePassportTokens($user);

            $user->workspaces()->detach();
            $user->update([
                'account_id' => null,
                'current_workspace_id' => null,
            ]);
            $user->delete();

            return $mediaPaths;
        });
    }

    /**
     * Process members still pointing at a shared account after workspace/account teardown.
     *
     * @param  bool  $onlyWithoutAccountWorkspaces  When true, only members with no
     *                                              remaining memberships on this
     *                                              account's workspaces are processed
     *                                              (workspace delete). When false,
     *                                              every other member is processed
     *                                              (account delete).
     * @param  bool  $forceDelete  When true, never restore to a personal account
     *                             (owner account delete).
     * @return list<string>
     */
    public static function forAccountMembers(
        Account $account,
        ?string $exceptUserId = null,
        bool $onlyWithoutAccountWorkspaces = false,
        bool $forceDelete = false,
    ): array {
        $mediaPaths = [];

        User::query()
            ->where('account_id', $account->id)
            ->when(
                $exceptUserId,
                fn ($query) => $query->where('id', '!=', $exceptUserId),
            )
            ->when(
                $onlyWithoutAccountWorkspaces,
                fn ($query) => $query->whereDoesntHave(
                    'workspaces',
                    fn ($workspaces) => $workspaces->where('workspaces.account_id', $account->id),
                ),
            )
            ->get()
            ->each(function (User $member) use ($account, $forceDelete, &$mediaPaths): void {
                $mediaPaths = [
                    ...$mediaPaths,
                    ...self::execute($member, $account, $forceDelete),
                ];
            });

        return $mediaPaths;
    }

    /**
     * Tear down every account the user owns outside the leaving account
     * (personal leftovers during forced account-delete cleanup).
     *
     * @return list<string>
     */
    private static function purgeOwnedAccounts(User $user, Account $leavingAccount): array
    {
        $mediaPaths = [];

        Account::query()
            ->where('owner_id', $user->id)
            ->where('id', '!=', $leavingAccount->id)
            ->get()
            ->each(function (Account $owned) use ($user, &$mediaPaths): void {
                Workspace::query()
                    ->where('account_id', $owned->id)
                    ->get()
                    ->each(function (Workspace $workspace) use (&$mediaPaths): void {
                        $mediaPaths = [
                            ...$mediaPaths,
                            ...DeleteWorkspaceMedia::purgeRecords($workspace),
                        ];

                        $workspace->posts()->delete();
                        $workspace->socialAccounts()->delete();
                        $workspace->signatures()->delete();
                        $workspace->labels()->delete();
                        $workspace->members()->detach();
                        $workspace->delete();
                    });

                if ($user->account_id === $owned->id) {
                    $user->update(['account_id' => null]);
                }

                $owned->delete();
            });

        return $mediaPaths;
    }

    private static function deleteEmptyPersonalAccounts(User $user, Account $leavingAccount): void
    {
        Account::query()
            ->where('owner_id', $user->id)
            ->where('id', '!=', $leavingAccount->id)
            ->whereDoesntHave('workspaces')
            ->get()
            ->each(function (Account $orphan) use ($user): void {
                if ($user->account_id === $orphan->id) {
                    $user->update(['account_id' => null]);
                }

                $orphan->delete();
            });
    }

    /**
     * @return list<string>
     */
    private static function purgeUserMediaRecords(User $user): array
    {
        $userMediaQuery = Media::query()
            ->where('mediable_type', Relation::getMorphAlias(User::class))
            ->where('mediable_id', $user->id);

        /** @var list<string> $mediaPaths */
        $mediaPaths = $userMediaQuery->pluck('path')->all();
        $userMediaQuery->delete();

        return $mediaPaths;
    }

    private static function revokePassportTokens(User $user): void
    {
        $user->tokens()->each(function (Token $token): void {
            $token->revoke();
            $token->refreshToken?->revoke();
        });
    }
}
