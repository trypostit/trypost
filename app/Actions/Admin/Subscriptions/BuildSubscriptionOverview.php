<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Dto\Analytics\DateRange;
use App\Enums\Admin\SubscriptionStatusFilter;
use App\Models\Offer;
use App\Models\Subscription;
use App\Support\Admin\StripePriceAmounts;
use App\Support\Admin\SubscriptionHistory;
use App\Support\Admin\SubscriptionPlans;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
    public static function execute(DateRange $range, SubscriptionHistory $history): array
    {
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
                'mrr_cents' => StripePriceAmounts::monthlyCentsOf($subscriptions->filter(fn (Subscription $subscription): bool => self::paidAt($subscription, $first))),
            ],
            'now' => [
                'alive' => data_get($standing, SubscriptionStatusFilter::All->value),
                'active' => data_get($standing, SubscriptionStatusFilter::Active->value),
                'past_due' => data_get($standing, SubscriptionStatusFilter::PastDue->value),
                'trialing' => data_get($standing, SubscriptionStatusFilter::Trialing->value),
                'cancelling' => data_get($standing, SubscriptionStatusFilter::Cancelling->value),
            ],
            'period' => [
                'new' => self::startedIn($subscriptions, $range)->count(),
                'canceled' => $history->canceledIn($range)->count(),
                'alive_at_start' => $history->aliveAt($first)->count(),
                'churn_rate' => $history->churnRate($range),
            ],
            'days' => $range->calendarDays(),
            'alive_by_day' => $history->aliveByDay($range),
            'new_by_day' => $range->perDay($newPerDay),
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
                'past_due_cents' => StripePriceAmounts::monthlyCentsOf($alive->where('stripe_status', StripeSubscription::STATUS_PAST_DUE)->whereNull('ends_at')),
                'cancelling_cents' => StripePriceAmounts::monthlyCentsOf($alive->whereNotNull('ends_at')->whereIn('stripe_status', Subscription::PAYING_STATUSES)),
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

        return collect([data_get($plan, 'plan'), data_get($plan, 'interval')])->implode('|');
    }

    /**
     * The monthly revenue of the paying subscriptions at each price's list
     * amount, a yearly one counting a twelfth. A price Stripe could not tell
     * is left out and counted.
     *
     * @param  Collection<int, Subscription>  $alive
     * @return array{cents: int, unpriced: int}
     */
    private static function mrr(Collection $alive): array
    {
        $paying = $alive->whereIn('stripe_status', Subscription::PAYING_STATUSES)->whereNotNull('stripe_price');
        $amounts = $paying->map(fn (Subscription $subscription): ?float => StripePriceAmounts::forSubscription($subscription));
        $priced = $amounts->reject(fn (?float $amount): bool => $amount === null);

        return [
            'cents' => (int) round($priced->sum()),
            'unpriced' => $paying->count() - $priced->count(),
        ];
    }

    /**
     * Each offer's redemptions, and how many of those accounts still pay.
     *
     * @param  Collection<int, Subscription>  $alive
     * @return list<array{slug: string, redeemed: int, still_subscribed: int}>
     */
    private static function offers(Collection $alive): array
    {
        $aliveAccounts = $alive->pluck('account_id')->unique()->values();

        return Offer::query()
            ->whereHas('redemptions')
            ->withCount([
                'redemptions',
                'redemptions as still_subscribed_count' => fn (Builder $redemptions): Builder => $redemptions->whereIn('accounts.id', $aliveAccounts),
            ])
            ->get()
            ->map(fn (Offer $offer): array => [
                'slug' => $offer->slug,
                'redeemed' => (int) $offer->redemptions_count,
                'still_subscribed' => (int) $offer->still_subscribed_count,
            ])
            ->sortByDesc('redeemed')
            ->values()
            ->all();
    }
}
