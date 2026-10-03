<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Models\RssFeedCollection;

class RenameRssFeedCollection
{
    /**
     * @param  array{name: string}  $data
     */
    public static function execute(RssFeedCollection $collection, array $data): RssFeedCollection
    {
        $collection->update(['name' => trim((string) data_get($data, 'name'))]);

        return $collection;
    }
}
