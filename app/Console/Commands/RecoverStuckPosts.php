<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Models\Post;
use Illuminate\Console\Command;

class RecoverStuckPosts extends Command
{
    protected $signature = 'social:recover-stuck-posts';

    protected $description = 'Recover posts stuck in publishing status for more than 1 hour';

    public function handle(): void
    {
        $count = 0;

        Post::query()
            ->where('status', PostStatus::Publishing)
            ->where('updated_at', '<=', now()->subHour())
            ->each(function (Post $post) use (&$count) {
                $post->postPlatforms()
                    ->where('enabled', true)
                    ->whereIn('status', [PlatformStatus::Publishing, PlatformStatus::Pending, PlatformStatus::Retrying])
                    ->where('updated_at', '<=', now()->subHour())
                    ->update([
                        'status' => PlatformStatus::Failed,
                        'error_message' => __('posts.errors.publishing_timed_out'),
                        'error_context' => [
                            'category' => 'timeout',
                            'failed_at' => now()->toIso8601String(),
                        ],
                    ]);

                // Delayed platform-unavailable retries keep the platform Retrying with a
                // fresh updated_at — do not finalize the post while that work is still live.
                $stillActive = $post->postPlatforms()
                    ->where('enabled', true)
                    ->whereIn('status', [PlatformStatus::Publishing, PlatformStatus::Pending, PlatformStatus::Retrying])
                    ->exists();

                if ($stillActive) {
                    return;
                }

                $enabledPlatforms = $post->postPlatforms()->where('enabled', true)->get();
                $total = $enabledPlatforms->count();
                $publishedCount = $enabledPlatforms->where('status', PlatformStatus::Published)->count();

                if ($publishedCount === $total) {
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
