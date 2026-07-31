<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AcceptInvite
{
    /**
     * @return 'gone'|'already_accepted'|'already'|'accepted'|'wrong_email'
     */
    public static function execute(User $user, Invite $invite): string
    {
        if ($invite->email !== $user->email) {
            return 'wrong_email';
        }

        return DB::transaction(function () use ($user, $invite): string {
            $lockedInvite = Invite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                return 'gone';
            }

            if ($lockedInvite->accepted_at !== null) {
                return 'already_accepted';
            }

            $workspaces = ResolveInviteWorkspaces::execute($lockedInvite);

            if ($workspaces->isEmpty()) {
                $lockedInvite->delete();

                return 'gone';
            }

            // Already on the account: still attach any missing workspace memberships.
            if ($user->account_id === $lockedInvite->account_id) {
                AttachInviteWorkspaces::execute($user, $lockedInvite, $workspaces);

                $lockedInvite->update(['accepted_at' => now()]);

                return 'already';
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

            return 'accepted';
        });
    }
}
