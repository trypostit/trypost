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
     * @return LengthAwarePaginator<int, RepurposeItem>
     */
    public static function execute(Repurpose $repurpose): LengthAwarePaginator
    {
        return $repurpose->items()
            ->with('posts.postPlatforms:id,post_id,platform,enabled,status')
            ->orderByDesc(DB::raw('coalesce(source_created_at, created_at)'))
            ->paginate((int) config('app.pagination.default'));
    }
}
