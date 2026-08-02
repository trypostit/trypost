<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\User\SettleStrandedMember;
use App\Actions\User\StrandedSettlement;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        $settlement = StrandedSettlement::none();

        DB::transaction(function () use ($workspace, $userId, &$settlement): void {
            $account = $workspace->account;

            // Serialize with DeleteWorkspace / other RemoveMember calls on this
            // account so concurrent removals cannot skip stranded cleanup.
            if ($account?->id) {
                Account::query()->whereKey($account->id)->lockForUpdate()->first();
            }

            $user = User::query()->find($userId);

            $workspace->members()->detach($userId);

            if (! $user) {
                return;
            }

            $user->refresh();

            if ($user->current_workspace_id === $workspace->id) {
                ReassignCurrentWorkspace::forUserAwayFrom($user, $workspace);
                $user->refresh();
            }

            // Last membership on this shared account — delete the invitee.
            if (
                $account
                && $user->account_id === $account->id
                && $user->id !== $account->owner_id
            ) {
                $settlement = SettleStrandedMember::execute($user, $account);
            }
        });

        $settlement->flush();
    }
}
