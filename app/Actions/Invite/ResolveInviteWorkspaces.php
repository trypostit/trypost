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
        return collect(data_get($invite, 'workspaces', []))
            ->map(fn (mixed $workspaceId): ?Workspace => Workspace::query()->find($workspaceId))
            ->filter(fn (?Workspace $workspace): bool => $workspace !== null
                && $workspace->account_id === $invite->account_id)
            ->values();
    }
}
