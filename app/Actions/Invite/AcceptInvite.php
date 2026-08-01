<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\Account\DeleteOwnedAccount;
use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Enums\Invite\Result;
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

        $previousAccountId = null;

        $result = DB::transaction(function () use ($user, $invite, &$previousAccountId): Result {
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

            return Result::Accepted;
        });

        // Leaving a personal account (empty invite shell, or any leftover):
        // abandon it after commit so Stripe cancel is not held inside the lock.
        if ($result === Result::Accepted && $previousAccountId) {
            $mediaPaths = DeleteOwnedAccount::execute($user, $previousAccountId);

            if ($mediaPaths !== null) {
                DeleteOrphanedMediaFiles::execute($mediaPaths);
            }
        }

        return $result;
    }
}
