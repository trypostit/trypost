<?php

declare(strict_types=1);

namespace App\Actions\SavedReply;

use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
use Illuminate\Database\Eloquent\Builder;

class ListSavedReplies
{
    /**
     * @return Builder<WorkspaceSavedReply>
     */
    public static function execute(Workspace $workspace, ?string $search = null): Builder
    {
        return WorkspaceSavedReply::query()
            ->where('workspace_id', $workspace->id)
            ->when(filled($search), fn (Builder $query) => $query->where(fn (Builder $matches) => $matches
                ->whereLike('name', "%{$search}%")
                ->orWhereLike('content', "%{$search}%")))
            ->latest()
            ->orderByDesc('id');
    }
}
