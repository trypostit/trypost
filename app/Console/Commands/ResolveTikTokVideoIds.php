<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResolveTikTokVideoId;
use App\Models\Post;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ResolveTikTokVideoIds extends Command
{
    protected $signature = 'social:resolve-tiktok-video-ids';

    protected $description = 'Ask TikTok for the video ids of public posts still on a publish_id';

    /**
     * TikTok has reported a video id weeks after the publish, so the sweep keeps
     * asking for a month, less often as the post gets older.
     */
    private const int RESOLVE_WITHIN_DAYS = 30;

    private const int RECHECK_NEW_POST_AFTER_MINUTES = 5;

    private const int RECHECK_FIRST_DAY_AFTER_MINUTES = 60;

    private const int RECHECK_OLDER_POST_AFTER_MINUTES = 1440;

    public function handle(): int
    {
        Post::query()
            ->publishedToTikTok()
            ->whereHas('socialAccount', fn (Builder $query): Builder => $query->connected())
            ->where('published_at', '>=', now()->subDays(self::RESOLVE_WITHIN_DAYS))
            ->select(['id', 'platform', 'origin', 'publish_status', 'platform_post_id', 'meta', 'published_at', 'last_reconciled_at'])
            ->lazyById()
            ->filter(fn (Post $post): bool => $post->awaitsTikTokVideoId() && $this->isDue($post))
            ->each(fn (Post $post) => ResolveTikTokVideoId::dispatch($post));

        return self::SUCCESS;
    }

    /**
     * Moderation usually ends within a minute, so a new post is checked on
     * every run, then hourly through its first day, then daily.
     */
    private function isDue(Post $post): bool
    {
        if (blank($post->last_reconciled_at)) {
            return true;
        }

        $recheckAfterMinutes = match (true) {
            $post->published_at->greaterThan(now()->subHour()) => self::RECHECK_NEW_POST_AFTER_MINUTES,
            $post->published_at->greaterThan(now()->subDay()) => self::RECHECK_FIRST_DAY_AFTER_MINUTES,
            default => self::RECHECK_OLDER_POST_AFTER_MINUTES,
        };

        return $post->last_reconciled_at->lessThanOrEqualTo(now()->subMinutes($recheckAfterMinutes));
    }
}
