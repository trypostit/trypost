<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Dto\SourceMedia;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Services\Repurpose\SourceFetcherFactory;
use App\Services\Social\TokenRedactor;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PollRepurposeSource implements ShouldBeUnique, ShouldQueue
{
    public int $uniqueFor = 600;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $deleteWhenMissingModels = true;

    private const STRANDED_AFTER_MINUTES = 30;

    /** @var array<string, string|null> */
    private array $downloadUrls = [];

    public function __construct(public SocialAccount $account)
    {
        $this->onQueue($account->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    public function handle(SourceFetcherFactory $fetchers): void
    {
        $repurposes = $this->activeRepurposes();

        if ($repurposes->isEmpty()) {
            return;
        }

        if ($this->account->disconnected_at !== null) {
            $this->reschedule($repurposes);

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

        $publishedByUs = $this->idsPublishedByTryPost($media);

        try {
            foreach ($repurposes as $repurpose) {
                $this->queueNewMedia($repurpose, $media, $publishedByUs);
            }
        } catch (Throwable $exception) {
            $this->recordFailure($repurposes, $exception);

            return;
        }

        $this->markPolled($repurposes);
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
    private function earliestWatermark(Collection $repurposes): ?CarbonInterface
    {
        return $repurposes->pluck('activated_at')->filter()->min();
    }

    /**
     * @param  array<int, SourceMedia>  $media
     * @param  array<int, string>  $publishedByUs
     */
    private function queueNewMedia(Repurpose $repurpose, array $media, array $publishedByUs): void
    {
        collect($media)
            ->filter(fn (SourceMedia $entry): bool => $entry->format === $repurpose->source_format)
            ->reject(fn (SourceMedia $entry): bool => $entry->predates($repurpose->activated_at))
            ->each(fn (SourceMedia $entry) => $this->recordMedia($repurpose, $entry, $publishedByUs));
    }

    /**
     * @param  array<int, string>  $publishedByUs
     */
    private function recordMedia(Repurpose $repurpose, SourceMedia $entry, array $publishedByUs): void
    {
        $item = RepurposeItem::query()
            ->where('repurpose_id', $repurpose->id)
            ->where('source_media_id', $entry->id)
            ->first();

        if ($item !== null) {
            if ($this->isStranded($item)) {
                $this->process($item, $entry);
            }

            return;
        }

        if (in_array($entry->id, $publishedByUs, true)) {
            $this->createItem($repurpose, $entry, ['status' => ItemStatus::Skipped, 'reason' => ItemReason::PublishedViaTrypost]);

            return;
        }

        $downloadUrl = $this->downloadUrl($entry);

        $item = $this->createItem($repurpose, $entry, blank($downloadUrl)
            ? ['status' => ItemStatus::Skipped, 'reason' => ItemReason::MediaUrlMissing]
            : ['status' => ItemStatus::Pending]);

        if ($item->wasRecentlyCreated && $item->status === ItemStatus::Pending) {
            ProcessRepurposeItem::dispatch($item, (string) $downloadUrl, $entry->caption);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createItem(Repurpose $repurpose, SourceMedia $entry, array $attributes): RepurposeItem
    {
        return RepurposeItem::firstOrCreate(
            ['repurpose_id' => $repurpose->id, 'source_media_id' => $entry->id],
            [
                ...$attributes,
                'source_permalink' => $entry->permalink,
                'source_created_at' => $entry->createdAt,
            ],
        );
    }

    private function isStranded(RepurposeItem $item): bool
    {
        return $item->status === ItemStatus::Pending
            && $item->created_at->lessThanOrEqualTo(now()->subMinutes(self::STRANDED_AFTER_MINUTES));
    }

    private function process(RepurposeItem $item, SourceMedia $entry): void
    {
        $downloadUrl = $this->downloadUrl($entry);

        if (blank($downloadUrl)) {
            $item->update(['status' => ItemStatus::Skipped, 'reason' => ItemReason::MediaUrlMissing]);

            return;
        }

        ProcessRepurposeItem::dispatch($item, $downloadUrl, $entry->caption);
    }

    private function downloadUrl(SourceMedia $entry): ?string
    {
        if (! array_key_exists($entry->id, $this->downloadUrls)) {
            $this->downloadUrls[$entry->id] = $entry->resolveDownloadUrl();
        }

        return $this->downloadUrls[$entry->id];
    }

    /**
     * @param  array<int, SourceMedia>  $media
     * @return array<int, string>
     */
    private function idsPublishedByTryPost(array $media): array
    {
        $ids = array_map(fn (SourceMedia $entry): string => $entry->id, $media);

        if ($ids === []) {
            return [];
        }

        return Post::query()
            ->whereIn('platform_post_id', $ids)
            ->createdInTryPost()
            ->where('workspace_id', $this->account->workspace_id)
            ->pluck('platform_post_id')
            ->all();
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function recordFailure(Collection $repurposes, Throwable $exception): void
    {
        $throttled = $exception instanceof SourceFetchException && $exception->isTransient();

        $message = Str::limit(TokenRedactor::redact($exception->getMessage()), 1000);

        Repurpose::whereKey($repurposes->modelKeys())->update([
            'last_error' => $message,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($throttled ? $this->backoffMinutes() : $this->intervalMinutes()),
        ]);

        Log::error('Repurpose polling failed', [
            'social_account_id' => $this->account->id,
            'message' => $message,
        ]);
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function reschedule(Collection $repurposes): void
    {
        Repurpose::whereKey($repurposes->modelKeys())->update([
            'next_poll_at' => now()->addMinutes($this->intervalMinutes()),
        ]);
    }

    /**
     * @param  Collection<int, Repurpose>  $repurposes
     */
    private function markPolled(Collection $repurposes): void
    {
        Repurpose::whereKey($repurposes->modelKeys())->update([
            'last_error' => null,
            'last_polled_at' => now(),
            'next_poll_at' => now()->addMinutes($this->intervalMinutes()),
        ]);
    }

    private function intervalMinutes(): int
    {
        return (int) config('trypost.repurpose.poll_interval_minutes');
    }

    private function backoffMinutes(): int
    {
        return (int) config('trypost.repurpose.backoff_minutes');
    }
}
