<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\PostPlatform\ContentType;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The channel's published feed posts and reels, newest first, as Instagram
 * lays them out on the profile grid. Stories never reach the grid.
 */
class ListInstagramGridPosts
{
    /**
     * @return LengthAwarePaginator<int, PostPlatform>
     */
    public static function execute(SocialAccount $account): LengthAwarePaginator
    {
        return PostPlatform::query()
            ->select(['id', 'post_id', 'content_type', 'published_at'])
            ->where('social_account_id', $account->id)
            ->enabled()
            ->published()
            ->whereIn('content_type', [ContentType::InstagramFeed, ContentType::InstagramReel])
            ->with('post:id,media')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate((int) config('app.pagination.default'));
    }
}
