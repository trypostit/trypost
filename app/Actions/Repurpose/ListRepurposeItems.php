<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;
use App\Models\RepurposeItem;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListRepurposeItems
{
    /**
     * The one place the activity query lives. It was duplicated in the API
     * controller and the MCP tool, and both kept an eager load that had since
     * gained a column — so the resource read a status the query never selected.
     *
     * @param  int|null  $perPage  Only the public API passes this: its page size
     *                             is a documented contract, not the app default.
     * @return LengthAwarePaginator<int, RepurposeItem>
     */
    public static function execute(Repurpose $repurpose, ?int $page = null, ?int $perPage = null): LengthAwarePaginator
    {
        return $repurpose->items()
            ->with('posts.postPlatforms:id,post_id,platform,enabled,status')
            ->orderByDesc(DB::raw('coalesce(source_created_at, created_at)'))
            ->paginate($perPage ?? (int) config('app.pagination.default'), page: $page);
    }
}
