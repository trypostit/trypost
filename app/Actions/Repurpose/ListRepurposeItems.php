<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;
use App\Models\RepurposeItem;
use Illuminate\Pagination\LengthAwarePaginator;

class ListRepurposeItems
{
    /**
     * @return LengthAwarePaginator<int, RepurposeItem>
     */
    public static function execute(Repurpose $repurpose): LengthAwarePaginator
    {
        return $repurpose->items()
            ->with('posts.postPlatforms:id,post_id,platform')
            ->latest()
            ->paginate((int) config('app.pagination.default'));
    }
}
