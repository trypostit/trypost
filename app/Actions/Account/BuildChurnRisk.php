<?php

declare(strict_types=1);

namespace App\Actions\Account;

use App\Dto\Account\AccountActivity;
use App\Enums\Account\ChurnSignal;
use App\Enums\Account\LifecycleStage;
use App\Enums\Account\RiskLevel;
use App\Models\Account;
use App\Models\Subscription;
use App\Support\Account\AccountSummary;
use App\Support\Billing\StripePriceAmounts;
use App\Support\Percent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Stripe\Subscription as StripeSubscription;

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

    /** Days a new customer has to connect a channel and post before not doing so is a signal. */
    public const int SETTLING_DAYS = 7;

    /** The share of an account's channels that need reconnecting for it to count. */
    private const float BROKEN_CHANNELS_SHARE = 50.0;

    /**
     * The paying accounts that may leave (so TryPost Cloud's only), each
     * with the signals that say so: it stopped posting (its lifecycle stage,
     * worked out now), never posted or has no channel a week after
     * subscribing, posts half as much as in the period before, fails often,
     * most of its channels need reconnecting, it is cancelling or its
     * payment is past due. Each signal weighs in a risk level; the heaviest
     * first, then what the account pays. The score only orders the rows and
     * sets the level; it is not sent.
     *
     * @return array<string, mixed>
     */
    public static function execute(AccountActivity $activity): array
    {
        $accounts = Account::query()
            ->whereHas('subscriptions', fn (Builder $subscriptions): Builder => $subscriptions->paying())
            ->with(['owner', 'plan', 'subscriptions'])
            ->withLifecycleActivity()
            ->get();

        $atRisk = $accounts
            ->map(function (Account $account) use ($activity): array {
                $stage = ResolveLifecycleStage::execute($account, $account->last_published_at, (bool) $account->has_social_account);
                $sent = $activity->sentBy($account->id);
                $previousSent = $activity->previousSentBy($account->id);

                return [
                    ...AccountSummary::of($account),
                    'account_id' => $account->id,
                    'posts' => $sent,
                    'previous_posts' => $previousSent,
                    'stage' => $stage->value,
                    'last_posted_at' => $account->last_published_at?->toIso8601String(),
                    ...self::assess($account, $stage, $activity->channelsOf($account->id), $sent, $previousSent, $activity->failedFor($account->id)),
                ];
            })
            ->filter(fn (array $row): bool => filled(data_get($row, 'signals')))
            ->sortByDesc(fn (array $row): array => [data_get($row, 'score'), data_get($row, 'mrr_cents')])
            ->map(fn (array $row): array => Arr::except($row, 'score'))
            ->values();

        return [
            'base' => $accounts->count(),
            'active' => $accounts->filter(fn (Account $account): bool => $activity->sentBy($account->id) > 0)->count(),
            'at_risk' => $atRisk->count(),
            'at_risk_share' => Percent::of($atRisk->count(), $accounts->count()),
            'mrr_at_risk_cents' => $atRisk->sum('mrr_cents'),
            'levels' => $atRisk->countBy('level')->all(),
            'signals' => $atRisk->flatMap(fn (array $row): array => data_get($row, 'signals'))->countBy()->sortDesc()->all(),
            'accounts' => $atRisk->take(self::LIST_SIZE)->all(),
        ];
    }

    /**
     * One account's churn signals, how much they weigh, its risk level (null
     * without a signal) and what it pays a month, through the subscription it
     * pays with now (none for an account that pays nothing).
     *
     * @param  array{total: int, broken: int}|null  $channels
     * @return array{signals: list<string>, score: int, level: ?string, mrr_cents: int}
     */
    public static function assess(Account $account, LifecycleStage $stage, ?array $channels, int $sent, int $previousSent, int $failed): array
    {
        $subscription = $account->payingSubscription();
        $signals = collect(self::signals($account, $stage, $subscription, $channels, $sent, $previousSent, $failed));
        $score = $signals->sum(fn (ChurnSignal $signal): int => $signal->weight());

        return [
            'signals' => $signals->map(fn (ChurnSignal $signal): string => $signal->value)->all(),
            'score' => $score,
            'level' => RiskLevel::fromScore($score)?->value,
            'mrr_cents' => StripePriceAmounts::monthlyCentsOf(Collection::wrap($subscription)),
        ];
    }

    /**
     * @param  array{total: int, broken: int}|null  $channels
     * @return list<ChurnSignal>
     */
    private static function signals(Account $account, LifecycleStage $stage, ?Subscription $subscription, ?array $channels, int $sent, int $previousSent, int $failed): array
    {
        $signals = [];

        $settled = ($subscription?->created_at ?? $account->created_at)->lte(now()->subDays(self::SETTLING_DAYS));

        if ($stage === LifecycleStage::AtRisk) {
            $signals[] = ChurnSignal::AtRisk;
        }

        if ($stage === LifecycleStage::Dormant) {
            $signals[] = ChurnSignal::Dormant;
        }

        if ($settled && $stage === LifecycleStage::Connected) {
            $signals[] = ChurnSignal::NeverPosted;
        }

        if (filled($subscription?->ends_at)) {
            $signals[] = ChurnSignal::Cancelling;
        }

        if ($subscription?->stripe_status === StripeSubscription::STATUS_PAST_DUE) {
            $signals[] = ChurnSignal::PastDue;
        }

        if ($settled && blank($channels)) {
            $signals[] = ChurnSignal::NoChannels;
        }

        if (filled($channels) && Percent::of(data_get($channels, 'broken'), data_get($channels, 'total')) >= self::BROKEN_CHANNELS_SHARE) {
            $signals[] = ChurnSignal::ChannelsBroken;
        }

        if ($previousSent >= self::DROP_MIN_POSTS && Percent::of($sent, $previousSent) <= self::DROP_SHARE) {
            $signals[] = ChurnSignal::PostingDropped;
        }

        if ($failed >= self::FAILURE_MIN_POSTS && Percent::of($failed, $failed + $sent) >= self::FAILURE_RATE) {
            $signals[] = ChurnSignal::Failing;
        }

        return $signals;
    }
}
