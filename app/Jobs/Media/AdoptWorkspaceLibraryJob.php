<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Actions\Media\AdoptWorkspaceLibrary;
use App\Models\Workspace;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases are bounded by time, not attempts, so a post that stays in
 * Publishing or a library that needs several runs never exhausts the job.
 */
class AdoptWorkspaceLibraryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Its own queue and Horizon supervisor, so the release can adopt many
     * workspaces in parallel without holding up the `default` queue.
     */
    public const string QUEUE = 'media-adoption';

    public const int RETRY_PUBLISHING_AFTER = 300;

    public const int RETRY_DEFERRED_AFTER = 5;

    public const int RETRY_HOURS = 6;

    public const int UNIQUE_FOR_SECONDS = (self::RETRY_HOURS + 1) * 3600;

    public int $maxExceptions = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public int $uniqueFor = self::UNIQUE_FOR_SECONDS;

    /**
     * Seconds one attempt spends adopting before it hands the rest to the
     * next attempt; well under `$timeout`.
     */
    public int $budgetSeconds = 420;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  array<string, list<string>>|null  $foreignReferences  this workspace's slice of `AdoptWorkspaceLibrary::foreignReferences()`; null computes it within the budget
     */
    public function __construct(public Workspace $workspace, public ?array $foreignReferences = null)
    {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return $this->workspace->id;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(self::RETRY_HOURS);
    }

    public function handle(): void
    {
        $result = AdoptWorkspaceLibrary::execute($this->workspace, now()->addSeconds($this->budgetSeconds), $this->foreignReferences);

        if ($result['deferred'] > 0) {
            $this->release(self::RETRY_DEFERRED_AFTER);

            return;
        }

        if ($result['skipped'] > 0) {
            $this->release(self::RETRY_PUBLISHING_AFTER);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('media:adopt-library gave up on a workspace; its library was not deleted', [
            'workspace_id' => $this->workspace->id,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
