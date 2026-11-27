<?php

declare(strict_types=1);

namespace App\Actions\Admin\Users;

use App\Actions\Account\ResolveLifecycleStage;
use App\Enums\Account\LifecycleStage;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Status;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildChurnRisk
{
    public const int LIST_SIZE = 100;

    /** Posts sent in the period before, under which a drop says nothing. */
    private const int DROP_MIN_POSTS = 5;

    /** The share of the period before's posts a dropping account sends at most. */
    private const float DROP_SHARE = 50.0;

    /** Failures in the period, and their share of the attempts, from which an account fails too much. */
    private const int FAILURE_MIN_POSTS = 5;

    private const float FAILURE_RATE = 20.0;

    /** How much each signal weighs: money already leaving counts most, a slowdown least. */
    private const array WEIGHTS = [
        'cancelling' => 3,
        'past_due' => 3,
        'dormant' => 2,
        'never_posted' => 2,
        'no_channels' => 2,
        'channels_broken' => 2,
        'at_risk' => 1,
        'posting_dropped' => 1,
        'failing' => 1,
    ];

    /** The weight from which an account's risk is high, and from which it is medium. */
    private const int HIGH_RISK = 4;

    private const int MEDIUM_RISK = 2;

    /** Days a new customer has to connect a channel and post before not doing so is a signal. */
    private const int SETTLING_DAYS = 7;

    /** The share of an account's channels that need reconnecting for it to count. */
    private const float BROKEN_CHANNELS_SHARE = 50.0;

    /**
     * The paying accounts that may leave (so TryPost Cloud's only), each
     * with the signals that say so: it stopped posting (its lifecycle stage,
     * worked out now), never posted or has no channel a week after
     * subscribing, posts half as much as in the period before, fails often,
     * most of its channels need reconnecting, it is cancelling or its
     * payment is past due. Each signal weighs in a risk level; the heaviest
     * first, then what the account pays.
     *
     * @return array<string, mixed>
     */
    public static function execute(string $start, string $end, string $timezone): array
    {
        $first = CarbonImmutable::parse($start, $timezone)->startOfDay();
        $last = CarbonImmutable::parse($end, $timezone)->endOfDay();
        $length = (int) $first->diffInDays($last->startOfDay()) + 1;

        $subscriptions = self::paidSubscriptions();
        $channels = self::channelHealth();
        $base = $subscriptions->keys();

        $sent = BuildUsageInsights::postsPerAccount($first, $last, PublishStatus::wentOutCases());
        $previousSent = BuildUsageInsights::postsPerAccount($first->subDays($length), $first->subSecond(), PublishStatus::wentOutCases());
        $failed = BuildUsageInsights::postsPerAccount($first, $last, [PublishStatus::Failed]);

        $accounts = Account::query()
            ->whereIn('id', $base)
            ->with(['owner', 'plan', 'subscriptions'])
            ->withLifecycleActivity()
            ->get()
            ->keyBy('id');

        $atRisk = $accounts
            ->map(function (Account $account) use ($subscriptions, $channels, $sent, $previousSent, $failed): array {
                $stage = ResolveLifecycleStage::execute($account, $account->last_published_at, (bool) $account->has_social_account);

                return [
                    ...BuildUsageInsights::accountSummary($account),
                    'account_id' => $account->id,
                    'posts' => (int) $sent->get($account->id, 0),
                    'previous_posts' => (int) $previousSent->get($account->id, 0),
                    'stage' => $stage->value,
                    'last_posted_at' => $account->last_published_at?->toIso8601String(),
                    ...self::assess($account, $stage, $subscriptions->get($account->id), $channels->get($account->id), (int) $sent->get($account->id, 0), (int) $previousSent->get($account->id, 0), (int) $failed->get($account->id, 0)),
                ];
            })
            ->filter(fn (array $row): bool => filled($row['signals']))
            ->sortByDesc(fn (array $row): array => [$row['score'], $row['mrr_cents']])
            ->values();

        return [
            'base' => $accounts->count(),
            'active' => $accounts->keys()->filter(fn (string $accountId): bool => $sent->get($accountId, 0) > 0)->count(),
            'at_risk' => $atRisk->count(),
            'at_risk_share' => Percent::of($atRisk->count(), $accounts->count()),
            'mrr_at_risk_cents' => $atRisk->sum('mrr_cents'),
            'levels' => $atRisk->countBy('level')->all(),
            'signals' => $atRisk->flatMap(fn (array $row): array => $row['signals'])->countBy()->sortDesc()->all(),
            'accounts' => $atRisk->take(self::LIST_SIZE)->all(),
        ];
    }

    /**
     * One paying account's churn signals, how much they weigh, its risk
     * level (null without a signal) and what it pays a month.
     *
     * @param  array{total: int, broken: int}|null  $channels
     * @return array{signals: list<string>, score: int, level: ?string, mrr_cents: int}
     */
    public static function assess(Account $account, LifecycleStage $stage, ?Subscription $subscription, ?array $channels, int $sent, int $previousSent, int $failed): array
    {
        $signals = self::signals($account, $stage, $subscription, $channels, $sent, $previousSent, $failed);

        return [
            'signals' => $signals,
            'score' => self::score($signals),
            'level' => self::level(self::score($signals)),
            'mrr_cents' => self::monthlyCents($subscription),
        ];
    }

    /**
     * @param  array{total: int, broken: int}|null  $channels
     * @return list<string>
     */
    private static function signals(Account $account, LifecycleStage $stage, ?Subscription $subscription, ?array $channels, int $sent, int $previousSent, int $failed): array
    {
        $signals = [];

        $settled = ($subscription?->created_at ?? $account->created_at)->lte(now()->subDays(self::SETTLING_DAYS));

        if (in_array($stage, [LifecycleStage::AtRisk, LifecycleStage::Dormant], true)) {
            $signals[] = $stage->value;
        }

        if ($settled && $stage === LifecycleStage::Connected) {
            $signals[] = 'never_posted';
        }

        if ($subscription?->ends_at !== null) {
            $signals[] = 'cancelling';
        }

        if ($subscription?->stripe_status === 'past_due') {
            $signals[] = 'past_due';
        }

        if ($settled && $channels === null) {
            $signals[] = 'no_channels';
        }

        if ($channels !== null && Percent::of($channels['broken'], $channels['total']) >= self::BROKEN_CHANNELS_SHARE) {
            $signals[] = 'channels_broken';
        }

        if ($previousSent >= self::DROP_MIN_POSTS && Percent::of($sent, $previousSent) <= self::DROP_SHARE) {
            $signals[] = 'posting_dropped';
        }

        if ($failed >= self::FAILURE_MIN_POSTS && Percent::of($failed, $failed + $sent) >= self::FAILURE_RATE) {
            $signals[] = 'failing';
        }

        return $signals;
    }

    /**
     * @param  list<string>  $signals
     */
    private static function score(array $signals): int
    {
        return collect($signals)->sum(fn (string $signal): int => (int) data_get(self::WEIGHTS, $signal, 1));
    }

    private static function level(int $score): ?string
    {
        if ($score === 0) {
            return null;
        }

        if ($score >= self::HIGH_RISK) {
            return 'high';
        }

        if ($score >= self::MEDIUM_RISK) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Each paying account's subscription (alive, active or past due), keyed
     * by account id, or one account's only.
     *
     * @return Collection<string, Subscription>
     */
    public static function paidSubscriptions(?string $accountId = null): Collection
    {
        return Subscription::query()
            ->where('type', Account::SUBSCRIPTION_NAME)
            ->when(filled($accountId), fn ($query) => $query->where('account_id', $accountId))
            ->whereIn('stripe_status', ['active', 'past_due'])
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->get(['id', 'account_id', 'stripe_status', 'stripe_price', 'quantity', 'ends_at', 'created_at'])
            ->keyBy('account_id');
    }

    /**
     * Each account's channels, and how many of them need reconnecting, or
     * one account's only.
     *
     * @return Collection<string, array{total: int, broken: int}>
     */
    public static function channelHealth(?string $accountId = null): Collection
    {
        return SocialAccount::query()
            ->withoutGlobalScopes()
            ->join('workspaces', 'workspaces.id', '=', 'social_accounts.workspace_id')
            ->when(filled($accountId), fn ($query) => $query->where('workspaces.account_id', $accountId))
            ->toBase()
            ->get(['workspaces.account_id', 'social_accounts.status'])
            ->groupBy('account_id')
            ->map(fn (Collection $rows): array => [
                'total' => $rows->count(),
                'broken' => $rows->whereIn('status', [Status::TokenExpired->value, Status::Disconnected->value])->count(),
            ]);
    }

    private static function monthlyCents(?Subscription $subscription): int
    {
        if ($subscription === null || blank($subscription->stripe_price)) {
            return 0;
        }

        return (int) round((StripePriceAmounts::monthlyCents($subscription->stripe_price) ?? 0.0) * max(1, (int) $subscription->quantity));
    }
}
