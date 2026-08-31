<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Console\Command;

class RecoverStuckPosts extends Command
{
    protected $signature = 'social:recover-stuck-posts';

    protected $description = 'Recover posts stuck in publishing status for more than 1 hour';

    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $count = 0;

        Post::query()
            ->where('status', PostStatus::Publishing)
            ->where('updated_at', '<=', now()->subHour())
            ->each(function (Post $post) use (&$count) {
                $stalePlatforms = $post->postPlatforms()
                    ->enabled()
                    ->whereIn('status', [PlatformStatus::Publishing, PlatformStatus::Pending, PlatformStatus::Retrying])
                    ->where('updated_at', '<=', now()->subHour())
                    ->get();

                $stalePlatforms->each(function (PostPlatform $postPlatform): void {
                    $this->tiktokPhotoDerivativeCleaner->cleanupUnlessPublishInFlight(
                        $postPlatform->error_context,
                        $postPlatform->id,
                    );

                    $postPlatform->update([
                        'status' => PlatformStatus::Failed,
                        'error_message' => __('posts.errors.publishing_timed_out'),
                        'error_context' => [
                            ...($postPlatform->error_context ?? []),
                            'category' => ErrorCategory::Timeout->value,
                            'failed_at' => now()->toIso8601String(),
                        ],
                    ]);
                });

                // Delayed platform-unavailable retries keep the platform Retrying with a
                // fresh updated_at — do not finalize the post while that work is still live.
                $stillActive = $post->postPlatforms()
                    ->enabled()
                    ->whereIn('status', [
                        PlatformStatus::Publishing,
                        PlatformStatus::Pending,
                        PlatformStatus::Retrying,
                        PlatformStatus::Submitted,
                        PlatformStatus::PendingReview,
                    ])
                    ->exists();

                if ($stillActive) {
                    return;
                }

                $enabledPlatforms = $post->postPlatforms()->enabled()->get();
                $total = $enabledPlatforms->count();
                $publishedCount = $enabledPlatforms->where('status', PlatformStatus::Published)->count();

                if ($total === 0) {
                    $post->markAsFailed();
                } elseif ($publishedCount === $total) {
                    $post->markAsPublished();
                } elseif ($publishedCount > 0) {
                    $post->markAsPartiallyPublished();
                } else {
                    $post->markAsFailed();
                }

                $count++;
            });

        $this->info("Recovered {$count} stuck posts.");
    }
}
