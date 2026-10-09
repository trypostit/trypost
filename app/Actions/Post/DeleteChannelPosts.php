<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deletes every post of a channel that is going away, whatever its status or
 * origin, quietly: no post.deleted webhook, notification or observer.
 * Analytics publications stay and are unlinked by the FK, so the same
 * identity connected again imports its posts afresh.
 */
class DeleteChannelPosts
{
    public static function forAccount(SocialAccount $account): int
    {
        $deletedPosts = 0;

        Post::query()
            ->where('social_account_id', $account->id)
            ->select(['id', 'platform'])
            ->chunkById(PruneExpiredPostHistory::CHUNK, function (Collection $posts) use (&$deletedPosts): void {
                self::pruneGoogleBusinessImages($posts);

                DB::transaction(function () use ($posts): void {
                    DeleteOwnedMedia::forPosts($posts->modelKeys());
                    Post::query()->whereKey($posts->modelKeys())->delete();
                });

                $deletedPosts += $posts->count();
            });

        return $deletedPosts;
    }

    /**
     * Deletes the images once the caller's transaction commits, so a rolled
     * back disconnect keeps them.
     *
     * @param  Collection<int, Post>  $posts
     */
    private static function pruneGoogleBusinessImages(Collection $posts): void
    {
        $googleBusiness = $posts->filter(fn (Post $post): bool => $post->platform === Platform::GoogleBusiness)->values();

        DB::afterCommit(fn () => $googleBusiness->each(fn (Post $post) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($post)));
    }
}
