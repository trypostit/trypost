<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the backoffice counts per account, in SQL: the posts it sent and the
 * channels it has. Every page that reads an account's activity counts it
 * here, for every account at once or for one.
 */
class CountAccountActivity
{
    /** The channel statuses that need reconnecting. */
    private const array BROKEN_STATUSES = [Status::TokenExpired, Status::Disconnected];

    /**
     * How many posts each account sent then, by publication status and,
     * optionally, by how they were made. Imported posts are left out.
     *
     * @param  list<PublishStatus>  $statuses
     * @param  list<CreatedVia>  $via
     * @return Collection<string, int>
     */
    public static function posts(CarbonImmutable $from, CarbonImmutable $until, array $statuses, array $via = []): Collection
    {
        return self::postsQuery($from, $until, $statuses, $via)
            ->tallyBy(['workspaces.account_id'])
            ->toBase()
            ->pluck('total', 'account_id')
            ->map(fn (mixed $total): int => (int) $total);
    }

    /**
     * How many posts one account sent then, as {@see self::posts()} counts them.
     *
     * @param  list<PublishStatus>  $statuses
     * @param  list<CreatedVia>  $via
     */
    public static function postsOf(string $accountId, CarbonImmutable $from, CarbonImmutable $until, array $statuses, array $via = []): int
    {
        return self::postsQuery($from, $until, $statuses, $via)
            ->where('workspaces.account_id', $accountId)
            ->count();
    }

    /**
     * Each account's channels, and how many of them need reconnecting.
     *
     * @return Collection<string, array{total: int, broken: int}>
     */
    public static function channels(): Collection
    {
        return self::channelHealth(SocialAccount::query());
    }

    /**
     * One account's channels, and how many of them need reconnecting; null
     * without a channel.
     *
     * @return array{total: int, broken: int}|null
     */
    public static function channelsOf(string $accountId): ?array
    {
        return self::channelHealth(SocialAccount::query()->where('workspaces.account_id', $accountId))->get($accountId);
    }

    /**
     * @param  list<PublishStatus>  $statuses
     * @param  list<CreatedVia>  $via
     * @return Builder<Post>
     */
    private static function postsQuery(CarbonImmutable $from, CarbonImmutable $until, array $statuses, array $via): Builder
    {
        return Post::query()
            ->createdInTryPost()
            ->join('workspaces', 'workspaces.id', '=', 'posts.workspace_id')
            ->whereIn('posts.publish_status', $statuses)
            ->whereBetween('posts.publication_updated_at', [$from, $until])
            ->when(filled($via), fn (Builder $query): Builder => $query->whereIn('posts.created_via', $via));
    }

    /**
     * @param  Builder<SocialAccount>  $query
     * @return Collection<string, array{total: int, broken: int}>
     */
    private static function channelHealth(Builder $query): Collection
    {
        return $query
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->tallyBy(['workspaces.account_id', 'social_accounts.status'])
            ->toBase()
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $groups): array => [
                'total' => (int) $groups->sum('total'),
                'broken' => (int) $groups->whereIn('status', collect(self::BROKEN_STATUSES)->map->value->all())->sum('total'),
            ]);
    }
}
