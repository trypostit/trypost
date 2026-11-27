<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder;

class ListFailedPosts
{
    /**
     * Posts the networks refused, across every workspace, the most recently
     * failed first (`updated_at`, set on every post, sorts the same on
     * PostgreSQL and MySQL; `publication_updated_at` may be null),
     * optionally only those of one error category.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return Builder<Post>
     */
    public static function execute(array $platforms = [], ?ErrorCategory $category = null): Builder
    {
        return self::failed($platforms)
            ->with(['socialAccount', 'workspace.account.owner'])
            ->when($category, fn (Builder $query, ErrorCategory $category) => self::inCategory($query, $category))
            ->orderByDesc('posts.updated_at')
            ->orderByDesc('posts.id');
    }

    /**
     * How many failed posts of the networks fall in each error category, the
     * largest first. A failure recorded without a category counts as unknown.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return array<string, int>
     */
    public static function categoryCounts(array $platforms = []): array
    {
        return self::failed($platforms)
            ->get(['posts.id', 'posts.error_context'])
            ->countBy(fn (Post $post): string => ErrorCategory::tryFromContext($post->error_context)?->value ?? ErrorCategory::Unknown->value)
            ->sortDesc()
            ->all();
    }

    /**
     * A failure recorded without a category is listed with the unknown ones,
     * as `categoryCounts()` counts it.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private static function inCategory(Builder $query, ErrorCategory $category): Builder
    {
        if ($category !== ErrorCategory::Unknown) {
            return $query->where('posts.error_context->category', $category->value);
        }

        return $query->where(fn (Builder $unknown) => $unknown
            ->where('posts.error_context->category', $category->value)
            ->orWhereNull('posts.error_context->category'));
    }

    /**
     * @param  list<Platform>  $platforms
     * @return Builder<Post>
     */
    private static function failed(array $platforms): Builder
    {
        return Post::query()
            ->failed()
            ->where('posts.publish_status', PublishStatus::Failed)
            ->when(filled($platforms), fn (Builder $query) => $query->whereIn('posts.platform', $platforms));
    }
}
