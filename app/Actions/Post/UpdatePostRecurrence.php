<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Models\Post;

class UpdatePostRecurrence
{
    /**
     * @param  array{interval: int, frequency: string, times: int}|null  $rule
     */
    public static function execute(Post $post, ?array $rule): void
    {
        $post->update($rule === null ? Post::withoutRecurrence() : [
            'recurrence_interval' => (int) data_get($rule, 'interval'),
            'recurrence_frequency' => data_get($rule, 'frequency'),
            'recurrence_remaining' => (int) data_get($rule, 'times'),
            'recurrence_anchor_at' => null,
            'recurrence_origin_at' => null,
        ]);
    }
}
