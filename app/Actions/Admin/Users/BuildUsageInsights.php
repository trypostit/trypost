<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Account\ResolveLifecycleStage;
use App\Enums\Account\LifecycleStage;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Models\Account;
use App\Support\Admin\AccountActivity;
use App\Support\Admin\AccountSummary;
use App\Support\Admin\KeyLabel;
use App\Support\Percent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildUsageInsights
{
    /** How many accounts each measure brings into the top accounts table. */
    public const int TOP_PER_MEASURE = 25;

    /** The stages of an account with access, in the order one goes through them. */
    public const array CUSTOMER_STAGES = [
        LifecycleStage::Onboarding,
        LifecycleStage::Connected,
        LifecycleStage::Active,
        LifecycleStage::AtRisk,
        LifecycleStage::Dormant,
    ];

    /** Channel counts an account falls in: label => [from, up to]. */
    private const array CHANNEL_BUCKETS = ['1' => [1, 1], '2 to 3' => [2, 3], '4 to 10' => [4, 10], '11 or more' => [11, PHP_INT_MAX]];

    /** Posts sent in the period an active account falls in: label => [from, up to]. */
    private const array POST_BUCKETS = ['1 to 5' => [1, 5], '6 to 20' => [6, 20], '21 to 100' => [21, 100], '101 or more' => [101, PHP_INT_MAX]];

    /**
     * How the accounts use TryPost over the range's days of the viewer's zone:
     * how many sent a post and how much, set against the period before; the
     * accounts by lifecycle stage; who sends, connects, automates and fails
     * the most; and how the accounts spread by channels and posts.
     *
     * @return array<string, mixed>
     */
    public static function execute(AccountActivity $activity): array
    {
        $range = $activity->range;
        $automated = CountAccountActivity::posts($range->startsAt(), $range->endsAt(), PublishStatus::wentOutCases(), [CreatedVia::Api, CreatedVia::Mcp]);
        $channels = $activity->channels->map(fn (array $health): int => data_get($health, 'total'));

        $topIds = collect([$activity->sent, $activity->failed, $automated, $channels])
            ->flatMap(fn (Collection $counts): array => $counts->sortDesc()->take(self::TOP_PER_MEASURE)->keys()->all())
            ->unique()
            ->values();
        $accounts = self::accounts($topIds);

        return [
            'totals' => [
                ...self::sending($activity->sent),
                'channels_per_account' => self::average($channels),
            ],
            'previous' => self::sending($activity->previousSent),
            'lifecycle' => self::lifecycle(),
            'top_accounts' => $topIds
                ->map(fn (string $accountId): array => [
                    ...AccountSummary::of($accounts->get($accountId)),
                    'account_id' => $accountId,
                    'posts' => $activity->sentBy($accountId),
                    'automated' => (int) $automated->get($accountId, 0),
                    'channels' => (int) $channels->get($accountId, 0),
                    'failed' => $activity->failedFor($accountId),
                ])
                ->sortByDesc('posts')
                ->values()
                ->all(),
            'channel_buckets' => self::buckets($channels, self::CHANNEL_BUCKETS),
            'post_buckets' => self::buckets($activity->sent, self::POST_BUCKETS),
        ];
    }

    /**
     * How many accounts sent a post and how much.
     *
     * @param  Collection<string, int>  $sent
     * @return array{active_accounts: int, posts: int, posts_per_active: float}
     */
    private static function sending(Collection $sent): array
    {
        return [
            'active_accounts' => $sent->count(),
            'posts' => (int) $sent->sum(),
            'posts_per_active' => self::average($sent),
        ];
    }

    /**
     * @param  Collection<string, int>  $counts
     */
    private static function average(Collection $counts): float
    {
        if ($counts->isEmpty()) {
            return 0.0;
        }

        return round($counts->sum() / $counts->count(), 1);
    }

    /**
     * Every account's lifecycle stage now, worked out by the app's own rule
     * from its access, channels and last post. The stage stored on the
     * account is only refreshed by the PostHog sync, which a self-hosted
     * install never runs, so it is not read here. Only an account with a
     * subscription not yet ended or a trial still running may have access,
     * so only those are read.
     *
     * @return Collection<string, LifecycleStage>
     */
    private static function liveStages(): Collection
    {
        return Account::query()
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('subscriptions', fn (Builder $subscriptions): Builder => $subscriptions->live())
                ->orWhere('trial_ends_at', '>', now()))
            ->with('subscriptions')
            ->withLifecycleActivity()
            ->get()
            ->mapWithKeys(fn (Account $account): array => [
                $account->id => ResolveLifecycleStage::execute($account, $account->last_published_at, (bool) $account->has_social_account),
            ]);
    }

    /**
     * The customers (accounts with access) by stage, in the order one goes
     * through them.
     *
     * @return array{customers: int, stages: list<array{key: string, label: string, count: int, share: float}>}
     */
    private static function lifecycle(): array
    {
        $counts = self::liveStages()->countBy(fn (LifecycleStage $stage): string => $stage->value);
        $customers = collect(self::CUSTOMER_STAGES)->sum(fn (LifecycleStage $stage): int => (int) $counts->get($stage->value, 0));

        return [
            'customers' => $customers,
            'stages' => collect(self::CUSTOMER_STAGES)
                ->map(fn (LifecycleStage $stage): array => [
                    'key' => $stage->value,
                    'label' => KeyLabel::of($stage->value),
                    'count' => (int) $counts->get($stage->value, 0),
                    'share' => Percent::of((int) $counts->get($stage->value, 0), $customers),
                ])
                ->all(),
        ];
    }

    /**
     * The accounts the top accounts table names, with their owner and plan.
     *
     * @param  Collection<int, string>  $ids
     * @return Collection<string, Account>
     */
    private static function accounts(Collection $ids): Collection
    {
        return Account::query()
            ->whereIn('id', $ids)
            ->with(['owner', 'plan'])
            ->get()
            ->keyBy('id');
    }

    /**
     * How many accounts fall in each range of a count.
     *
     * @param  Collection<string, int>  $counts
     * @param  array<string, array{0: int, 1: int}>  $ranges
     * @return list<array{key: string, label: string, count: int, share: float}>
     */
    private static function buckets(Collection $counts, array $ranges): array
    {
        return collect($ranges)
            ->map(function (array $range, string $label) use ($counts): array {
                $inRange = $counts->filter(fn (int $count): bool => $count >= $range[0] && $count <= $range[1])->count();

                return [
                    'key' => $label,
                    'label' => $label,
                    'count' => $inRange,
                    'share' => Percent::of($inRange, $counts->count()),
                ];
            })
            ->values()
            ->all();
    }
}
