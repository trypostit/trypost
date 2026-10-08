<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The channel's published feed posts and reels, newest first, as Instagram
 * lays them out on the profile grid. Stories never reach the grid.
 */
class ListInstagramGridPosts
{
    /**
     * @return LengthAwarePaginator<int, Post>
     */
    public static function execute(SocialAccount $account): LengthAwarePaginator
    {
        return Post::query()
            ->select(['id', 'media', 'content_type', 'published_at'])
            ->where('social_account_id', $account->id)
            ->publicationPublished()
            ->whereIn('content_type', [ContentType::InstagramFeed, ContentType::InstagramReel])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) config('app.pagination.default'));
    }
}
