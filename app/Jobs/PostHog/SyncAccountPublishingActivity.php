<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Models\Account;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncAccountPublishingActivity implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public const DEBOUNCE_SECONDS = 300;

    public int $tries = 10;

    public int $timeout = 30;

    public int $uniqueFor = 3600;

    public function __construct(public string $accountId)
    {
        $this->onQueue('posthog');
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("posthog-account-publishing:{$this->accountId}"))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function uniqueId(): string
    {
        return $this->accountId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 1800, 3600];
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Account::find($this->accountId);

        if (! $account) {
            return;
        }

        $workspaceIds = Workspace::query()
            ->select('id')
            ->whereBelongsTo($account);
        $postIds = Post::query()
            ->select('id')
            ->whereIn('workspace_id', $workspaceIds);

        $latestPublication = PostPlatform::query()
            ->published()
            ->whereNotNull('published_at')
            ->whereIn('post_id', $postIds)
            ->latest('published_at')
            ->latest('updated_at')
            ->latest('id')
            ->first();

        $postHog->groupIdentifyNow('account', (string) $account->id, [
            'last_post_published_at' => $latestPublication?->published_at?->toIso8601String(),
            'last_post_published_network' => $latestPublication?->platform->network(),
            'last_published_post_id' => $latestPublication?->post_id,
        ]);
    }
}
