<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TikTok\PrivacyLevel;
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

    private const int RECHECK_NEW_POST_AFTER_MINUTES = 10;

    private const int RECHECK_FIRST_DAY_AFTER_MINUTES = 60;

    private const int RECHECK_OLDER_POST_AFTER_MINUTES = 1440;

    public function handle(): int
    {
        Post::query()
            ->publishedToTikTok()
            ->where('meta->privacy_level', PrivacyLevel::PublicToEveryone->value)
            ->whereHas('socialAccount', fn (Builder $query): Builder => $query->connected())
            ->where('published_at', '>=', now()->subDays(self::RESOLVE_WITHIN_DAYS))
            ->select(['id', 'platform', 'origin', 'publish_status', 'platform_post_id', 'meta', 'published_at', 'last_reconciled_at'])
            ->lazyById()
            ->filter(fn (Post $post): bool => $post->awaitsTikTokVideoId() && $this->isDue($post))
            ->each(fn (Post $post) => ResolveTikTokVideoId::dispatch($post));

        return self::SUCCESS;
    }

    /**
     * The publish job already asks a minute after the publish, so this sweep
     * (every fifteen minutes) only catches slower reviews: on every run
     * through a post's first hour, hourly through its first day and daily
     * after that.
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
