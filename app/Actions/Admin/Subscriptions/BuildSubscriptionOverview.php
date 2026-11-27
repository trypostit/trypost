<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Dto\Analytics\DateRange;
use App\Enums\Admin\SubscriptionStatusFilter;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Subscription;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Admin\SubscriptionHistory;
use App\Support\Admin\SubscriptionPlans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Stripe\Subscription as StripeSubscription;

class BuildSubscriptionOverview
{
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
        $history = SubscriptionHistory::read();
        $subscriptions = $history->subscriptions;
        $first = $range->startsAt();
        $previous = $range->previous();
        $alive = $history->aliveAt($history->now);
        $standing = SubscriptionStatusFilter::counts($alive, $history->now);
        $newPerDay = $subscriptions->countBy(fn (Subscription $subscription): string => $range->localDate($subscription->created_at));

        return [
            'previous' => [
                'new' => self::startedIn($subscriptions, $previous)->count(),
                'canceled' => $history->canceledIn($previous)->count(),
                'churn_rate' => $history->churnRate($previous),
                'mrr_cents' => self::monthlyCents($subscriptions->filter(fn (Subscription $subscription): bool => self::paidAt($subscription, $first))),
            ],
            'now' => [
                'alive' => $standing[SubscriptionStatusFilter::All->value],
                'active' => $standing[SubscriptionStatusFilter::Active->value],
                'past_due' => $standing[SubscriptionStatusFilter::PastDue->value],
                'trialing' => $standing[SubscriptionStatusFilter::Trialing->value],
                'cancelling' => $standing[SubscriptionStatusFilter::Cancelling->value],
            ],
            'period' => [
                'new' => self::startedIn($subscriptions, $range)->count(),
                'canceled' => $history->canceledIn($range)->count(),
                'alive_at_start' => $history->aliveAt($first)->count(),
                'churn_rate' => $history->churnRate($range),
            ],
            'days' => $range->calendarDays(),
            'alive_by_day' => $history->aliveByDay($range),
            'new_by_day' => collect($range->calendarDays())->map(fn (string $day): int => (int) $newPerDay->get($day, 0))->all(),
            'canceled_by_day' => $history->canceledByDay($range),
            'churn_rate_by_day' => $history->churnRateByDay($range),
            'plans' => $alive
                ->groupBy(fn (Subscription $subscription): string => self::planKey($subscription))
                ->map(fn (Collection $group): array => [
                    ...SubscriptionPlans::describe($group->first()->stripe_price),
                    'count' => $group->count(),
                ])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'mrr' => self::mrr($alive),
            'at_risk' => [
                'past_due_cents' => self::monthlyCents($alive->where('stripe_status', StripeSubscription::STATUS_PAST_DUE)->whereNull('ends_at')),
                'cancelling_cents' => self::monthlyCents($alive->whereNotNull('ends_at')->whereIn('stripe_status', Subscription::PAYING_STATUSES)),
            ],
            'offers' => self::offers($alive),
        ];
    }

    /**
     * @param  Collection<int, Subscription>  $subscriptions
     * @return Collection<int, Subscription>
     */
    private static function startedIn(Collection $subscriptions, DateRange $range): Collection
    {
        return $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->created_at->between($range->startsAt(), $range->endsAt()));
    }

    /**
     * Alive then and past its trial: what the period's start paid for. The
     * status it had then is not kept, so a subscription in its trial at that
     * moment is the only one left out.
     */
    private static function paidAt(Subscription $subscription, CarbonImmutable $moment): bool
    {
        if (! $subscription->isAliveAt($moment)) {
            return false;
        }

        return $subscription->trial_ends_at === null || $subscription->trial_ends_at->lte($moment);
    }

    private static function planKey(Subscription $subscription): string
    {
        $plan = SubscriptionPlans::describe($subscription->stripe_price);

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
        $paying = $alive->whereIn('stripe_status', Subscription::PAYING_STATUSES)->whereNotNull('stripe_price');
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

        return Offer::query()
            ->whereHas('redemptions')
            ->with('redemptions')
            ->get()
            ->map(fn (Offer $offer): array => [
                'slug' => $offer->slug,
                'redeemed' => $offer->redemptions->count(),
                'still_subscribed' => $offer->redemptions->filter(fn (Account $account): bool => $aliveAccounts->has($account->id))->count(),
            ])
            ->sortByDesc('redeemed')
            ->values()
            ->all();
    }
}
