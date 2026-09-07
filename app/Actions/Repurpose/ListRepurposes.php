<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\ItemStatus;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ListRepurposes
{
    /**
     * @return LengthAwarePaginator<int, Repurpose>
     */
    public static function execute(Workspace $workspace, ?int $page = null): LengthAwarePaginator
    {
        return self::query($workspace)->paginate((int) config('app.pagination.default'), page: $page);
    }

    /**
     * The one place the list query lives. Duplicating it is how the activity
     * list ended up serving a column two of its three callers never selected.
     *
     * Exposed as a builder rather than taking a page size, because the public
     * API paginates at its own documented contract and an action must never be
     * handed one.
     *
     * @return Builder<Repurpose>
     */
    public static function query(Workspace $workspace): Builder
    {
        return Repurpose::query()
            ->where('workspace_id', $workspace->id)
            ->with('sourceAccount')
            ->withCount(['items as published_items_count' => fn ($query) => $query->where('status', ItemStatus::Published)])
            ->latest();
    }
}
