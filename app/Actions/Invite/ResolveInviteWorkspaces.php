<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Models\Invite;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class ResolveInviteWorkspaces
{
    /**
     * Workspaces listed on the invite that still exist on the invite's account.
     *
     * @return Collection<int, Workspace>
     */
    public static function execute(Invite $invite): Collection
    {
        $ids = collect(data_get($invite, 'workspaces', []))
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return Workspace::query()
            ->whereIn('id', $ids)
            ->where('account_id', $invite->account_id)
            ->get();
    }
}
