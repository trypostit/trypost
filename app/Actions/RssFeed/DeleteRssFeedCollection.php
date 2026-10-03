<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Models\RssFeedCollection;

class DeleteRssFeedCollection
{
    public static function execute(RssFeedCollection $collection): void
    {
        $collection->delete();
    }
}
