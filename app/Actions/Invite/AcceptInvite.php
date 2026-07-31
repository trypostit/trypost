<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Enums\Invite\Result;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvite
{
    public static function execute(User $user, Invite $invite): Result
    {
        if ($invite->email !== $user->email) {
            return Result::WrongEmail;
        }

        return DB::transaction(function () use ($user, $invite): Result {
            $lockedInvite = Invite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                return Result::Gone;
            }

            if ($lockedInvite->accepted_at !== null) {
                return Result::AlreadyAccepted;
            }

            $workspaces = ResolveInviteWorkspaces::execute($lockedInvite);

            if ($workspaces->isEmpty()) {
                $lockedInvite->delete();

                return Result::Gone;
            }

            // Already on the account: still attach any missing workspace memberships.
            if ($user->account_id === $lockedInvite->account_id) {
                AttachInviteWorkspaces::execute($user, $lockedInvite, $workspaces);

                $lockedInvite->update(['accepted_at' => now()]);

                return Result::AlreadyMember;
            }

            $previousAccountId = $user->account_id;

            $user->update(['account_id' => $lockedInvite->account_id]);
            $user->refresh();

            AttachInviteWorkspaces::execute($user, $lockedInvite, $workspaces);

            $lockedInvite->update(['accepted_at' => now()]);

            // Invite signup leaves an empty personal account; drop it once the
            // user has moved onto the shared invite account.
            if ($previousAccountId) {
                Account::query()
                    ->whereKey($previousAccountId)
                    ->where('owner_id', $user->id)
                    ->whereDoesntHave('workspaces')
                    ->delete();
            }

            return Result::Accepted;
        });
    }
}
