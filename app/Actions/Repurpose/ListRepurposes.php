<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

class ListRepurposes
{
    /**
     * @return Collection<int, Repurpose>
     */
    public static function execute(Workspace $workspace): Collection
    {
        return Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->with('sourceAccount')
            ->withCount(['items as published_items_count' => fn ($query) => $query->where('status', 'published')])
            ->latest()
            ->get();
    }
}
