<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Services\Repurpose\SourceMedia;
use App\Services\Social\Meta\GraphError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PollRepurposeSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public SocialAccount $account)
    {
        $this->onQueue($account->platform->queue());
    }

    public function handle(SourceFetcherFactory $fetchers): void
    {
        if ($this->account->disconnected_at !== null) {
            return;
        }

        $repurposes = $this->activeRepurposes();

        if ($repurposes->isEmpty()) {
            return;
        }

        try {
            $media = $fetchers->for($this->account)->fetch(
                $this->account,
                $this->earliestWatermark($repurposes),
                $this->watchedFormats($repurposes),
            );
        } catch (Throwable $exception) {
            $this->recordFailure($repurposes, $exception);

            return;
        }

        foreach ($repurposes as $repurpose) {
            $this->logMedia($repurpose, $media);
        }

        $this->markPolled($repurposes, $this->interval());
    }

    /**
     * @return Collection<int, Repurpose>
     */
    private function activeRepurposes(): Collection
    {
        return Repurpose::query()
            ->where('source_social_account_id', $this->account->id)
            ->where('status', Status::Active)
            ->get();
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     * @return array<int, SourceFormat>
     */
    private function watchedFormats(Collection $repurposes): array
    {
        return $repurposes->pluck('source_format')->unique()->values()->all();
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function earliestWatermark(Collection $repurposes): mixed
    {
        return $repurposes->pluck('activated_at')->filter()->min();
    }

    /**
     * @param  array<int, SourceMedia>  $media
     */
    private function logMedia(Repurpose $repurpose, array $media): void
    {
        $matching = array_values(array_filter(
            $media,
            fn (SourceMedia $entry): bool => $entry->format === $repurpose->source_format
                && ($repurpose->activated_at === null || $entry->createdAt === null || $entry->createdAt->greaterThan($repurpose->activated_at)),
        ));

        if ($matching === []) {
            return;
        }

        $publishedByUs = $this->idsPublishedByTryPost($repurpose, $matching);

        foreach ($matching as $entry) {
            $item = RepurposeItem::firstOrCreate(
                ['repurpose_id' => $repurpose->id, 'source_media_id' => $entry->id],
                [
                    'status' => ItemStatus::Pending,
                    'source_permalink' => $entry->permalink,
                    'source_created_at' => $entry->createdAt,
                ],
            );

            if (! $item->wasRecentlyCreated) {
                continue;
            }

            if (in_array($entry->id, $publishedByUs, true)) {
                $item->update(['status' => ItemStatus::Skipped, 'reason' => ItemReason::PublishedViaTrypost]);

                continue;
            }

            if (blank($entry->downloadUrl)) {
                $item->update(['status' => ItemStatus::Skipped, 'reason' => ItemReason::MediaUrlMissing]);

                continue;
            }

            ProcessRepurposeItem::dispatch($item, (string) $entry->downloadUrl, $entry->caption);
        }
    }

    /**
     * @param  array<int, SourceMedia>  $media
     * @return array<int, string>
     */
    private function idsPublishedByTryPost(Repurpose $repurpose, array $media): array
    {
        $ids = array_map(fn (SourceMedia $entry): string => $entry->id, $media);

        return PostPlatform::query()
            ->whereIn('platform_post_id', $ids)
            ->whereHas('post', fn (Builder $query) => $query->where('workspace_id', $repurpose->workspace_id))
            ->pluck('platform_post_id')
            ->all();
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function recordFailure(Collection $repurposes, Throwable $exception): void
    {
        $throttled = GraphError::isTransient(['error' => ['message' => $exception->getMessage()]])
            || Str::contains($exception->getMessage(), 'request limit', ignoreCase: true);

        Repurpose::whereKey($repurposes->modelKeys())->update([
            'last_error' => Str::limit($exception->getMessage(), 1000),
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($throttled ? $this->backoff() : $this->interval()),
        ]);

        Log::warning('Repurpose polling failed', [
            'social_account_id' => $this->account->id,
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function markPolled(Collection $repurposes, int $minutes): void
    {
        Repurpose::whereKey($repurposes->modelKeys())->update([
            'last_error' => null,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($minutes),
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
