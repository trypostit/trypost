<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Account\ResolveLifecycleStage;
use App\Enums\Account\LifecycleStage;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\PublishStatus;
use App\Models\Account;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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
     * How the accounts use TryPost between two days of the viewer's zone:
     * how many sent a post and how much, set against the period before; the
     * accounts by lifecycle stage; who sends, connects, automates and fails
     * the most; and how the accounts spread by channels and posts.
     *
     * @return array<string, mixed>
     */
    public static function execute(string $start, string $end, string $timezone): array
    {
        $first = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $last = CarbonImmutable::parse($end, $timezone)->endOfDay();
        $length = (int) $first->diffInDays($last->startOfDay()) + 1;

        $sent = self::postsPerAccount($first, $last, PublishStatus::wentOutCases());
        $previousSent = self::postsPerAccount($first->subDays($length), $first->subSecond(), PublishStatus::wentOutCases());
        $failed = self::postsPerAccount($first, $last, [PublishStatus::Failed]);
        $automated = self::postsPerAccount($first, $last, PublishStatus::wentOutCases(), [CreatedVia::Api, CreatedVia::Mcp]);
        $channels = SocialAccount::query()
            ->withoutGlobalScopes()
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->toBase()
            ->selectRaw('workspaces.account_id, count(*) as total')
            ->groupBy('workspaces.account_id')
            ->pluck('total', 'account_id')
            ->map(fn (mixed $total): int => (int) $total);

        $topIds = collect([$sent, $failed, $automated, $channels])
            ->flatMap(fn (Collection $counts): array => $counts->sortDesc()->take(self::TOP_PER_MEASURE)->keys()->all())
            ->unique()
            ->values();
        $accounts = self::accounts($topIds);

        return [
            'totals' => self::totals($sent, $channels),
            'previous' => self::totals($previousSent, $channels),
            'lifecycle' => self::lifecycle(),
            'top_accounts' => $topIds
                ->map(fn (string $accountId): array => [
                    ...self::accountSummary($accounts->get($accountId)),
                    'account_id' => $accountId,
                    'posts' => (int) $sent->get($accountId, 0),
                    'automated' => (int) $automated->get($accountId, 0),
                    'channels' => (int) $channels->get($accountId, 0),
                    'failed' => (int) $failed->get($accountId, 0),
                ])
                ->sortByDesc('posts')
                ->values()
                ->all(),
            'channel_buckets' => self::buckets($channels, self::CHANNEL_BUCKETS),
            'post_buckets' => self::buckets($sent, self::POST_BUCKETS),
        ];
    }

    /**
     * How many posts each account sent then, by publication status and,
     * optionally, by how they were made, or of one account only. Imported
     * posts are left out.
     *
     * @param  list<PublishStatus>  $statuses
     * @param  list<CreatedVia>  $via
     * @return Collection<string, int>
     */
    public static function postsPerAccount(CarbonImmutable $from, CarbonImmutable $until, array $statuses, array $via = [], ?string $accountId = null): Collection
    {
        return Post::query()
            ->createdInTryPost()
            ->join('workspaces', 'workspaces.id', '=', 'posts.workspace_id')
            ->whereIn('posts.publish_status', $statuses)
            ->whereBetween('posts.publication_updated_at', [$from, $until])
            ->when(filled($via), fn ($query) => $query->whereIn('posts.created_via', $via))
            ->when(filled($accountId), fn ($query) => $query->where('workspaces.account_id', $accountId))
            ->toBase()
            ->selectRaw('workspaces.account_id, count(*) as total')
            ->groupBy('workspaces.account_id')
            ->pluck('total', 'account_id')
            ->map(fn (mixed $total): int => (int) $total);
    }

    /**
     * @param  Collection<string, int>  $sent
     * @param  Collection<string, int>  $channels
     * @return array{active_accounts: int, posts: int, posts_per_active: float, channels_per_account: float}
     */
    private static function totals(Collection $sent, Collection $channels): array
    {
        return [
            'active_accounts' => $sent->count(),
            'posts' => $sent->sum(),
            'posts_per_active' => round($sent->count() === 0 ? 0 : $sent->sum() / $sent->count(), 1),
            'channels_per_account' => round($channels->count() === 0 ? 0 : $channels->sum() / $channels->count(), 1),
        ];
    }

    /**
     * Every account's lifecycle stage now, worked out by the app's own rule
     * from its access, channels and last post. The stage stored on the
     * account is only refreshed by the PostHog sync, which a self-hosted
     * install never runs, so it is not read here.
     *
     * @return Collection<string, LifecycleStage>
     */
    public static function liveStages(): Collection
    {
        return Account::query()
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
                    'label' => str($stage->value)->replace('_', ' ')->ucfirst()->toString(),
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
     * @return array{user_id: ?string, name: ?string, email: ?string, plan: ?string}
     */
    public static function accountSummary(?Account $account): array
    {
        return [
            'user_id' => $account?->owner?->id,
            'name' => self::displayName($account),
            'email' => $account?->owner?->email,
            'plan' => $account?->plan?->name,
        ];
    }

    /** The owner's name when it holds a letter or a digit (some sign up as "_"), else their email. */
    private static function displayName(?Account $account): ?string
    {
        $name = (string) $account?->owner?->name;

        if (Str::isMatch('/[\pL\pN]/u', $name)) {
            return $name;
        }

        return $account?->owner?->email;
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
            ->map(fn (array $range, string $label): array => [
                'key' => $label,
                'label' => $label,
                'count' => $counts->filter(fn (int $count): bool => $count >= $range[0] && $count <= $range[1])->count(),
                'share' => Percent::of($counts->filter(fn (int $count): bool => $count >= $range[0] && $count <= $range[1])->count(), $counts->count()),
            ])
            ->values()
            ->all();
    }
}
