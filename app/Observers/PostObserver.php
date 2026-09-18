<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Post\Status as PostStatus;
use App\Events\PostCreated;
use App\Events\PostStatusChanged;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));
    }

    public function saved(Post $post): void
    {
        if (! $post->wasChanged('status')) {
            return;
        }

        $previousStatus = $this->previousStatus($post);

        DB::afterCommit(fn () => PostStatusChanged::dispatch($post, $previousStatus));
    }

    private function previousStatus(Post $post): ?PostStatus
    {
        $previous = $post->getRawOriginal('status');

        if ($previous instanceof PostStatus) {
            return $previous;
        }

        if (is_string($previous)) {
            return PostStatus::tryFrom($previous);
        }

        return null;
    }
}
