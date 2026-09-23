<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\SyncTryPostPublication;
use App\Models\PostPlatform;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BackfillTryPostPublications implements ShouldQueue
{
    use Queueable;

    /** @param list<string> $postPlatformIds */
    public function __construct(public array $postPlatformIds)
    {
        $this->onQueue('analytics');
    }

    public function handle(SyncTryPostPublication $sync): void
    {
        PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereIn('id', $this->postPlatformIds)
            ->whereNotNull('social_account_id')
            ->with(['post', 'socialAccount'])
            ->get()
            ->each(fn (PostPlatform $postPlatform) => $sync->handle($postPlatform));
    }
}
