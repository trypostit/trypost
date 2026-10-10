<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SocialAccount\Platform;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\Post;
use Illuminate\Console\Command;

class ResolveTikTokVideoIds extends Command
{
    protected $signature = 'social:resolve-tiktok-video-ids';

    protected $description = 'Ask TikTok for the video ids of public posts still on a publish_id';

    /**
     * TikTok has reported a video id weeks after the publish, so the sweep keeps
     * asking for a month, less often as the post gets older.
     */
    public const int RESOLVE_WITHIN_DAYS = 30;

    public function handle(): int
    {
        Post::query()
            ->createdInTryPost()
            ->where('platform', Platform::TikTok)
            ->publicationPublished()
            ->where('published_at', '>=', now()->subDays(self::RESOLVE_WITHIN_DAYS))
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
        if ($post->last_reconciled_at === null) {
            return true;
        }

        $recheckAfterMinutes = match (true) {
            $post->published_at->greaterThan(now()->subHour()) => 5,
            $post->published_at->greaterThan(now()->subDay()) => 60,
            default => 1440,
        };

        return $post->last_reconciled_at->lessThanOrEqualTo(now()->subMinutes($recheckAfterMinutes));
    }
}
