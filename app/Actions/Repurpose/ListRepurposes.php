<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\ItemStatus;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Pagination\LengthAwarePaginator;

class ListRepurposes
{
    /**
     * @return LengthAwarePaginator<int, Repurpose>
     */
    public static function execute(Workspace $workspace, ?int $page = null): LengthAwarePaginator
    {
        return Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->with('sourceAccount')
            ->withCount(['items as published_items_count' => fn ($query) => $query->where('status', ItemStatus::Published)])
            ->latest()
            ->paginate((int) config('app.pagination.default'), page: $page);
    }
}
