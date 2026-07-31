<?php

declare(strict_types=1);

namespace App\Actions\User;

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Passport\Token;

class DeleteOrRestoreStrandedMember
{
    /**
     * After losing memberships on a shared account: restore a personal account
     * that still has workspaces, otherwise delete the user (and empty orphans).
     */
    public static function execute(User $user, Account $leavingAccount): void
    {
        if ($user->id === $leavingAccount->owner_id) {
            return;
        }

        if ($user->workspaces()
            ->where('workspaces.account_id', $leavingAccount->id)
            ->exists()
        ) {
            return;
        }

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

            return;
        }

        self::deleteEmptyPersonalAccounts($user, $leavingAccount);
        self::purgeUserMedia($user);
        self::revokePassportTokens($user);

        $user->workspaces()->detach();
        $user->update([
            'account_id' => null,
            'current_workspace_id' => null,
        ]);
        $user->delete();
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
     */
    public static function forAccountMembers(
        Account $account,
        ?string $exceptUserId = null,
        bool $onlyWithoutAccountWorkspaces = false,
    ): void {
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
            ->each(fn (User $member) => self::execute($member, $account));
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

    private static function purgeUserMedia(User $user): void
    {
        $userMediaQuery = Media::query()
            ->where('mediable_type', Relation::getMorphAlias(User::class))
            ->where('mediable_id', $user->id);

        $mediaPaths = $userMediaQuery->pluck('path')->all();
        $userMediaQuery->delete();

        DeleteOrphanedMediaFiles::execute($mediaPaths);
    }

    private static function revokePassportTokens(User $user): void
    {
        $user->tokens()->each(function (Token $token): void {
            $token->revoke();
            $token->refreshToken?->revoke();
        });
    }
}
