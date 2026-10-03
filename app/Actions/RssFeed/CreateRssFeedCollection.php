<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Models\RssFeedCollection;
use App\Models\Workspace;

class CreateRssFeedCollection
{
    /**
     * @param  array{name: string}  $data
     */
    public static function execute(Workspace $workspace, array $data): RssFeedCollection
    {
        $last = $workspace->rssFeedCollections()->max('position');

        return $workspace->rssFeedCollections()->create([
            'name' => trim((string) data_get($data, 'name')),
            'position' => $last === null ? 0 : (int) $last + 1,
        ]);
    }
}
