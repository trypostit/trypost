<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Support\Admin\PublishingCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListFailedPosts
{
    /**
     * Posts the networks refused or rejected in review (what the admin
     * calls failed everywhere), across every workspace, the most recently
     * failed first: by when the publication last moved
     * (`publication_updated_at`, the failure time the list shows), which an
     * edit of the post (a label, a note) never moves. A failure without that
     * time comes last on PostgreSQL and MySQL alike. Optionally only those
     * of one error category.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return Builder<Post>
     */
    public static function execute(array $platforms = [], ?ErrorCategory $category = null): Builder
    {
        return self::latestFailureFirst(self::failed($platforms)
            ->with(['socialAccount', 'workspace.account.owner'])
            ->when($category, fn (Builder $query, ErrorCategory $category) => self::inCategory($query, $category)));
    }

    /**
     * Orders failed posts the most recently failed first, by
     * `publication_updated_at` (nulls last on both engines), then by id.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    public static function latestFailureFirst(Builder $query): Builder
    {
        return $query
            ->orderByRaw('posts.publication_updated_at is null')
            ->orderByDesc('posts.publication_updated_at')
            ->orderByDesc('posts.id');
    }

    /**
     * How many failed posts of the networks fall in each error category, the
     * largest first, counted in SQL. A failure recorded without a category
     * counts as unknown.
     *
     * @param  list<Platform>  $platforms  empty means every platform
     * @return array<string, int>
     */
    public static function categoryCounts(array $platforms = []): array
    {
        return self::failed($platforms)
            ->tallyBy(['posts.error_context->category as category'])
            ->toBase()
            ->get()
            ->groupBy(fn (object $group): string => ErrorCategory::tryFrom((string) $group->category)?->value ?? ErrorCategory::Unknown->value)
            ->map(fn (Collection $groups): int => (int) $groups->sum('total'))
            ->sortDesc()
            ->all();
    }

    /**
     * A failure recorded without a category, or with one the enum does not
     * know (Google Business writes `review_unconfirmed`), is listed with the
     * unknown ones, as `categoryCounts()` counts it.
     *
     * @param  Builder<Post>  $query
     * @return Builder<Post>
     */
    private static function inCategory(Builder $query, ErrorCategory $category): Builder
    {
        if ($category !== ErrorCategory::Unknown) {
            return $query->where('posts.error_context->category', $category->value);
        }

        $known = collect(ErrorCategory::cases())
            ->reject(fn (ErrorCategory $case): bool => $case === ErrorCategory::Unknown)
            ->map(fn (ErrorCategory $case): string => $case->value)
            ->all();

        return $query->where(fn (Builder $unknown) => $unknown
            ->whereNull('posts.error_context->category')
            ->orWhereNotIn('posts.error_context->category', $known));
    }

    /**
     * @param  list<Platform>  $platforms
     * @return Builder<Post>
     */
    private static function failed(array $platforms): Builder
    {
        return Post::query()
            ->failed()
            ->whereIn('posts.publish_status', PublishingCounts::failedStatuses())
            ->when(filled($platforms), fn (Builder $query) => $query->whereIn('posts.platform', $platforms));
    }
}
