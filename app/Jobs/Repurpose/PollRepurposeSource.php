<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Services\Repurpose\SourceMedia;
use App\Services\Social\Meta\GraphError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PollRepurposeSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Repurpose $repurpose)
    {
        $this->onQueue($repurpose->sourceAccount->platform->queue());
    }

    public function handle(SourceFetcherFactory $fetchers): void
    {
        $account = $this->repurpose->sourceAccount;

        if ($account === null || $account->disconnected_at !== null) {
            return;
        }

        try {
            $media = $fetchers->for($account)->fetch($account, $this->repurpose->activated_at);
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            return;
        }

        $this->logMedia($media);

        $this->repurpose->update([
            'last_error' => null,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($this->interval()),
        ]);
    }

    /**
     * @param  array<int, SourceMedia>  $media
     */
    private function logMedia(array $media): void
    {
        $publishedByUs = $this->idsPublishedByTryPost($media);

        foreach ($media as $entry) {
            $item = RepurposeItem::firstOrCreate(
                ['repurpose_id' => $this->repurpose->id, 'source_media_id' => $entry->id],
                [
                    'status' => ItemStatus::Pending,
                    'source_permalink' => $entry->permalink,
                    'source_created_at' => $entry->createdAt,
                ],
            );

            if (! $item->wasRecentlyCreated) {
                continue;
            }

            $reason = $this->skipReason($entry, $publishedByUs);

            if ($reason !== null) {
                $item->update(['status' => ItemStatus::Skipped, 'reason' => $reason]);

                continue;
            }

            ProcessRepurposeItem::dispatch($item, (string) $entry->downloadUrl, $entry->caption);
        }
    }

    /**
     * @param  array<int, string>  $publishedByUs
     */
    private function skipReason(SourceMedia $media, array $publishedByUs): ?ItemReason
    {
        return match (true) {
            ! $media->isVideo => ItemReason::NotVideo,
            in_array($media->id, $publishedByUs, true) => ItemReason::PublishedViaTrypost,
            blank($media->downloadUrl) => ItemReason::MediaUrlMissing,
            default => null,
        };
    }

    /**
     * Media this workspace published through TryPost, which must never be
     * replicated again.
     *
     * @param  array<int, SourceMedia>  $media
     * @return array<int, string>
     */
    private function idsPublishedByTryPost(array $media): array
    {
        $ids = array_map(fn (SourceMedia $entry): string => $entry->id, $media);

        if ($ids === []) {
            return [];
        }

        return PostPlatform::query()
            ->whereIn('platform_post_id', $ids)
            ->whereHas('post', fn (Builder $query) => $query->where('workspace_id', $this->repurpose->workspace_id))
            ->pluck('platform_post_id')
            ->all();
    }

    /**
     * A throttled source waits longer than the usual interval, so a workspace
     * that hit Meta's app-wide quota does not keep spending it.
     */
    private function recordFailure(Throwable $exception): void
    {
        $throttled = GraphError::isTransient(['error' => ['message' => $exception->getMessage()]])
            || Str::contains($exception->getMessage(), 'request limit', ignoreCase: true);

        $this->repurpose->update([
            'last_error' => Str::limit($exception->getMessage(), 1000),
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($throttled ? $this->backoff() : $this->interval()),
        ]);

        Log::warning('Repurpose polling failed', [
            'repurpose_id' => $this->repurpose->id,
            'message' => $exception->getMessage(),
        ]);
    }

    private function interval(): int
    {
        return (int) config('trypost.repurpose.poll_interval_minutes');
    }

    private function backoff(): int
    {
        return (int) config('trypost.repurpose.backoff_minutes');
    }
}
