<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\SyncTryPostPublication as SyncTryPostPublicationAction;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Models\PostPlatform;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncTryPostPublication implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public TryPostPublicationIdentity $identity,
        public string $postPlatformId,
    ) {
        $this->onQueue('analytics');
    }

    public function handle(SyncTryPostPublicationAction $sync): void
    {
        $postPlatform = PostPlatform::query()
            ->published()
            ->with('post')
            ->find($this->postPlatformId);

        if (! $postPlatform || ! filled($postPlatform->platform_post_id)) {
            return;
        }

        $sync->fromIdentity($this->identity, $postPlatform);
    }
}
