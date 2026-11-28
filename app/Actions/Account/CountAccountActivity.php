<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Dto\Account\AccountActivity;
use App\Dto\Analytics\DateRange;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Analytics\PublishingCounts;
use App\Support\Social\NetworkFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the admin counts per account, in SQL: the posts it sent and the
 * channels it has. Every page that reads an account's activity counts it
 * here, for every account at once or for one.
 */
class CountAccountActivity
{
    /**
     * Every account's activity over the range, or only the given account's:
     * the posts sent in it and in the period before, those sent through the
     * API or MCP, the posts the networks refused (imported posts left out),
     * and the channels.
     */
    public static function execute(DateRange $range, ?string $accountId = null): AccountActivity
    {
        $previous = $range->previous();

        return new AccountActivity(
            $range,
            self::posts($range->startsAt(), $range->endsAt(), PublishStatus::wentOutCases(), $accountId),
            self::posts($previous->startsAt(), $previous->endsAt(), PublishStatus::wentOutCases(), $accountId),
            self::posts($range->startsAt(), $range->endsAt(), PublishStatus::wentOutCases(), $accountId, [CreatedVia::Api, CreatedVia::Mcp]),
            self::posts($range->startsAt(), $range->endsAt(), PublishingCounts::failedStatuses(), $accountId),
            self::channels($accountId),
        );
    }

    /**
     * How many posts each account sent then, by publication status and,
     * optionally, by how they were made.
     *
     * @param  list<PublishStatus>  $statuses
     * @param  list<CreatedVia>  $via
     * @return Collection<string, int>
     */
    private static function posts(CarbonImmutable $from, CarbonImmutable $until, array $statuses, ?string $accountId, array $via = []): Collection
    {
        return PublishingCounts::countedBetween(Post::query()->createdInTryPost(), $from, $until, $statuses)
            ->join('workspaces', 'workspaces.id', '=', 'posts.workspace_id')
            ->when($accountId, fn (Builder $query, string $id): Builder => $query->where('workspaces.account_id', $id))
            ->when(filled($via), fn (Builder $query): Builder => $query->whereIn('posts.created_via', $via))
            ->tallyBy(['workspaces.account_id'])
            ->toBase()
            ->pluck('total', 'account_id')
            ->map(fn (mixed $total): int => (int) $total);
    }

    /**
     * Each account's channels, and how many of them need reconnecting.
     *
     * @return Collection<string, array{total: int, broken: int}>
     */
    private static function channels(?string $accountId): Collection
    {
        $reconnect = collect(NetworkFilter::RECONNECT_STATUSES)->map->value->all();

        return SocialAccount::query()
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->when($accountId, fn (Builder $query, string $id): Builder => $query->where('workspaces.account_id', $id))
            ->tallyBy(['workspaces.account_id', 'social_accounts.status'])
            ->toBase()
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $groups): array => [
                'total' => (int) $groups->sum('total'),
                'broken' => (int) $groups->whereIn('status', $reconnect)->sum('total'),
            ]);
    }
}
