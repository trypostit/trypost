<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeclineInvite
{
    /**
     * @return 'wrong_email'|'gone'|'declined'
     */
    public static function execute(User $user, Invite $invite): string
    {
        if ($invite->email !== $user->email) {
            return 'wrong_email';
        }

        $workspacesGone = DB::transaction(function () use ($invite): bool {
            $lockedInvite = Invite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedInvite) {
                return true;
            }

            $workspaces = ResolveInviteWorkspaces::execute($lockedInvite);
            $lockedInvite->delete();

            return $workspaces->isEmpty();
        });

        return $workspacesGone ? 'gone' : 'declined';
    }
}
