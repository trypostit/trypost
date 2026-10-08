<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\QueuePublicationMetricsForPage;
use App\Actions\Analytics\SyncTryPostPublication as SyncTryPostPublicationAction;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTryPostPublication implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TryPostPublicationIdentity $identity,
        public string $postId,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(SyncTryPostPublicationAction $sync, QueuePublicationMetricsForPage $metrics): void
    {
        $post = Post::query()
            ->publicationPublished()
            ->find($this->postId);

        if (! $post || ! filled($post->platform_post_id)) {
            return;
        }

        $publication = $sync->fromIdentity($this->identity, $post);
        $metrics->queue($publication);
    }
}
