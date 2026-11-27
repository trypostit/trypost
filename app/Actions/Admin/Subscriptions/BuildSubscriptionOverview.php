<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Dto\Analytics\DateRange;
use App\Models\Account;
use App\Models\Subscription;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Admin\SubscriptionPlans;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildSubscriptionOverview
{
    /** The window the daily churn rate looks back over, in days. */
    public const int ROLLING_DAYS = 30;

    /**
     * How the subscriptions are doing over the range's days of the viewer's zone: how many are
     * alive at the end of each day (exact, since an ended subscription keeps
     * its row with `ends_at`), how many started and ended each day, where
     * they stand now, the churn of the period, the plans, the offers and the
     * monthly revenue at list price (coupons not taken off).
     *
     * @return array<string, mixed>
     */
    public static function execute(DateRange $range): array
    {
        $now = CarbonImmutable::now('UTC');
        $first = $range->startsAt();
        $last = $range->endsAt();
        $previous = $range->previous();
        $days = collect($range->calendarDays());

        $subscriptions = self::billable();
        $alive = $subscriptions->filter(fn (Subscription $subscription): bool => self::aliveAt($subscription, $now));
        $prices = SubscriptionPlans::byPrice();
        $aliveAtStart = $subscriptions->filter(fn (Subscription $subscription): bool => self::aliveAt($subscription, $first))->count();
        $ended = $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->ends_at !== null && $subscription->ends_at->lte($now));
        $canceled = $ended->filter(fn (Subscription $subscription): bool => $subscription->ends_at->between($first, $last));

        $previousCanceled = $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->ends_at !== null
            && $subscription->ends_at->between($previous->startsAt(), $previous->endsAt()));
        $aliveAtPreviousStart = $subscriptions->filter(fn (Subscription $subscription): bool => self::aliveAt($subscription, $previous->startsAt()))->count();

        return [
            'previous' => [
                'new' => $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->created_at->between($previous->startsAt(), $previous->endsAt()))->count(),
                'canceled' => $previousCanceled->count(),
                'churn_rate' => Percent::of($previousCanceled->count(), $aliveAtPreviousStart),
                'mrr_cents' => self::monthlyCents($subscriptions->filter(fn (Subscription $subscription): bool => self::paidAt($subscription, $first))),
            ],
            'now' => [
                'alive' => $alive->count(),
                'active' => $alive->where('stripe_status', 'active')->whereNull('ends_at')->count(),
                'past_due' => $alive->where('stripe_status', 'past_due')->whereNull('ends_at')->count(),
                'trialing' => $alive->where('stripe_status', 'trialing')->whereNull('ends_at')->count(),
                'cancelling' => $alive->whereNotNull('ends_at')->count(),
            ],
            'period' => [
                'new' => $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->created_at->between($first, $last))->count(),
                'canceled' => $canceled->count(),
                'alive_at_start' => $aliveAtStart,
                'churn_rate' => Percent::of($canceled->count(), $aliveAtStart),
            ],
            'days' => $days->all(),
            'alive_by_day' => $days->map(fn (string $day): int => $subscriptions
                ->filter(fn (Subscription $subscription): bool => self::aliveAt($subscription, self::endOfDay($range, $day)))
                ->count())->all(),
            'new_by_day' => self::perDay($days, $subscriptions->map(fn (Subscription $subscription): string => $range->localDate($subscription->created_at))),
            'canceled_by_day' => self::perDay($days, $canceled->map(fn (Subscription $subscription): string => $range->localDate($subscription->ends_at))),
            'churn_rate_by_day' => $days->map(fn (string $day): float => self::rollingChurn($subscriptions, $ended, self::endOfDay($range, $day)))->all(),
            'plans' => $alive
                ->groupBy(fn (Subscription $subscription): string => self::planKey($subscription, $prices))
                ->map(fn (Collection $group): array => [
                    ...SubscriptionPlans::describe($group->first()->stripe_price, $prices),
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'mrr' => self::mrr($alive),
            'at_risk' => [
                'past_due_cents' => self::monthlyCents($alive->where('stripe_status', 'past_due')->whereNull('ends_at')),
                'cancelling_cents' => self::monthlyCents($alive->whereNotNull('ends_at')->whereIn('stripe_status', SubscriptionPlans::PAYING_STATUSES)),
            ],
            'offers' => self::offers($alive),
        ];
    }

    /**
     * Every subscription that ever billed (an incomplete checkout never did).
     *
     * @return Collection<int, Subscription>
     */
    public static function billable(): Collection
    {
        return Subscription::query()
            ->where('type', Account::SUBSCRIPTION_NAME)
            ->whereNotIn('stripe_status', SubscriptionPlans::NEVER_BILLED_STATUSES)
            ->get(['id', 'account_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at']);
    }

    /**
     * Alive then and past its trial: what the period's start paid for. The
     * status it had then is not kept, so a subscription in its trial at that
     * moment is the only one left out.
     */
    private static function paidAt(Subscription $subscription, CarbonImmutable $moment): bool
    {
        if (! self::aliveAt($subscription, $moment)) {
            return false;
        }

        return $subscription->trial_ends_at === null || $subscription->trial_ends_at->lte($moment);
    }

    /** Started by then and not ended yet. */
    public static function aliveAt(Subscription $subscription, CarbonImmutable $moment): bool
    {
        if ($subscription->created_at->gt($moment)) {
            return false;
        }

        return $subscription->ends_at === null || $subscription->ends_at->gt($moment);
    }

    /**
     * The share of the subscriptions alive 30 days before the moment that
     * ended in those 30 days.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     * @param  Collection<int, Subscription>  $ended
     */
    public static function rollingChurn(Collection $subscriptions, Collection $ended, CarbonImmutable $moment): float
    {
        $from = $moment->subDays(self::ROLLING_DAYS);
        $base = $subscriptions->filter(fn (Subscription $subscription): bool => self::aliveAt($subscription, $from))->count();
        $left = $ended->filter(fn (Subscription $subscription): bool => $subscription->ends_at->gt($from) && $subscription->ends_at->lte($moment))->count();

        return Percent::of($left, $base);
    }

    /**
     * How many of the dates fall on each day.
     *
     * @param  Collection<int, string>  $days
     * @param  Collection<int, string>  $dates
     * @return list<int>
     */
    public static function perDay(Collection $days, Collection $dates): array
    {
        $counts = $dates->countBy();

        return $days->map(fn (string $day): int => (int) $counts->get($day, 0))->values()->all();
    }

    /** The UTC instant a calendar day of the range ends in the viewer's zone. */
    public static function endOfDay(DateRange $range, string $day): CarbonImmutable
    {
        return DateRange::fromDates($day, $day, $range->timezone)->endsAt();
    }

    /**
     * @param  array<string, array{plan: string, name: string, interval: string}>  $prices
     */
    private static function planKey(Subscription $subscription, array $prices): string
    {
        $plan = SubscriptionPlans::describe($subscription->stripe_price, $prices);

        return "{$plan['plan']}|{$plan['interval']}";
    }

    /**
     * The monthly revenue of the paying subscriptions at each price's list
     * amount, a yearly one counting a twelfth. A price Stripe could not tell
     * is left out and counted.
     *
     * @param  Collection<int, Subscription>  $alive
     * @return array{cents: int, subscriptions: int, unpriced: int}
     */
    private static function mrr(Collection $alive): array
    {
        $paying = $alive->whereIn('stripe_status', SubscriptionPlans::PAYING_STATUSES)->whereNotNull('stripe_price');
        $amounts = $paying->map(fn (Subscription $subscription): ?float => StripePriceAmounts::forSubscription($subscription));
        $priced = $amounts->reject(fn (?float $amount): bool => $amount === null);

        return [
            'cents' => (int) round($priced->sum()),
            'subscriptions' => $priced->count(),
            'unpriced' => $paying->count() - $priced->count(),
        ];
    }

    /**
     * What the subscriptions charge per month at list price; one whose price
     * Stripe could not tell counts nothing.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     */
    private static function monthlyCents(Collection $subscriptions): int
    {
        return (int) round($subscriptions->sum(fn (Subscription $subscription): float => StripePriceAmounts::forSubscription($subscription) ?? 0.0));
    }

    /**
     * Each offer's redemptions, and how many of those accounts still pay.
     *
     * @param  Collection<int, Subscription>  $alive
     * @return list<array{slug: string, redeemed: int, still_subscribed: int}>
     */
    private static function offers(Collection $alive): array
    {
        $aliveAccounts = $alive->pluck('account_id')->flip();

        return DB::table('account_offer')
            ->join('offers', 'offers.id', '=', 'account_offer.offer_id')
            ->whereNotNull('account_offer.redeemed_at')
            ->get(['offers.slug', 'account_offer.account_id'])
            ->groupBy('slug')
            ->map(fn (Collection $redemptions, string $slug): array => [
                'slug' => $slug,
                'redeemed' => $redemptions->count(),
                'still_subscribed' => $redemptions->filter(fn (object $redemption): bool => $aliveAccounts->has($redemption->account_id))->count(),
            ])
            ->sortByDesc('redeemed')
            ->values()
            ->all();
    }
}
