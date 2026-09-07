<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;
use App\Models\RepurposeItem;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListRepurposeItems
{
    /**
     * @return LengthAwarePaginator<int, RepurposeItem>
     */
    public static function execute(Repurpose $repurpose, ?int $page = null): LengthAwarePaginator
    {
        return self::query($repurpose)->paginate((int) config('app.pagination.default'), page: $page);
    }

    /**
     * The one place the activity query lives. It was duplicated in the API
     * controller and the MCP tool, and both kept an eager load that had since
     * gained a column — so the resource read a status the query never selected.
     *
     * Exposed as a builder rather than taking a page size, because the public
     * API paginates at its own documented contract and an action must never be
     * handed one.
     *
     * @return HasMany<RepurposeItem, Repurpose>
     */
    public static function query(Repurpose $repurpose): HasMany
    {
        return $repurpose->items()
            ->with('posts.postPlatforms:id,post_id,platform,enabled,status')
            ->orderByDesc(DB::raw('coalesce(source_created_at, created_at)'));
    }
}
