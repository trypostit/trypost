<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Models\Post;

class SyncPostLabels
{
    /**
     * @param  array{labels: list<string>}  $data
     */
    public static function execute(Post $post, array $data): void
    {
        $post->labels()->sync(data_get($data, 'labels', []));
    }
}
