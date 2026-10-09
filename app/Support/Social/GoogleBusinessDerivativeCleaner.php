<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Models\Post;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GoogleBusinessDerivativeCleaner
{
    public const string DIRECTORY = 'google-business-derivatives';

    /**
     * The JPEG is named after the post. A post published before posts and
     * their destinations were merged keeps its destination's id until its
     * file is gone.
     */
    public static function pathFor(Post $post): string
    {
        $fileName = $post->legacy_target_id ?? $post->id;

        return self::DIRECTORY."/{$fileName}.jpg";
    }

    public function cleanup(Post $post): void
    {
        $path = self::pathFor($post);

        try {
            Storage::delete($path);
        } catch (Throwable $e) {
            Log::warning('Failed to prune Google Business Profile image derivative', [
                'post_id' => $post->id,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
