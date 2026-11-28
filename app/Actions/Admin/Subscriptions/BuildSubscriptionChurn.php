<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Dto\Analytics\DateRange;
use App\Enums\Admin\SubscriptionStatusFilter;
use App\Http\Resources\Admin\AdminSubscriptionResource;
use App\Models\Subscription;
use App\Support\Admin\SubscriptionHistory;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSubscriptionChurn
{
    private const int RECENT = 50;

    /** How long a canceled subscription lasted, in days: label => [from, below]. */
    private const array LIFETIMES = [
        'Under 1 month' => [0, 30],
        '1 to 3 months' => [30, 90],
        '3 to 6 months' => [90, 180],
        '6 months or more' => [180, PHP_INT_MAX],
    ];

    /**
     * Who left and when: the subscriptions that ended on the range's days of the viewer's zone
     * (the latest first), how long every ended subscription lasted, and, per
     * month an account first subscribed in, how many accounts still pay and
     * have not asked to cancel (one cancelling at its period end is lost; a
     * plan change that opened a new subscription is the same account).
     *
     * @return array<string, mixed>
     */
    public static function execute(DateRange $range, SubscriptionHistory $history): array
    {
        $subscriptions = $history->subscriptions;
        $now = $history->now;
        $canceled = $history->canceledIn($range);

        $recent = $canceled
            ->sortByDesc('ends_at')
            ->take(self::RECENT)
            ->values()
            ->load(['owner.owner', 'owner.redeemedOffers']);

        return [
            'summary' => [
                'canceled' => $canceled->count(),
                'churn_rate' => $history->churnRate($range),
                'average_lifetime_days' => (int) round((float) $canceled->avg(fn (Subscription $subscription): int => self::lifetimeDays($subscription))),
                'cancelling' => $subscriptions->filter(fn (Subscription $subscription): bool => SubscriptionStatusFilter::forSubscription($subscription, $now) === SubscriptionStatusFilter::Cancelling)->count(),
            ],
            'days' => $range->calendarDays(),
            'canceled_by_day' => $history->canceledByDay($range),
            'churn_rate_by_day' => $history->churnRateByDay($range),
            'recent' => $recent->map(fn (Subscription $subscription): array => [
                ...AdminSubscriptionResource::make($subscription)->resolve(),
                'lifetime_days' => self::lifetimeDays($subscription),
            ])->values()->all(),
            'lifetimes' => collect(self::LIFETIMES)
                ->map(fn (array $bounds, string $label): array => [
                    'label' => $label,
                    'count' => $history->ended->filter(fn (Subscription $subscription): bool => self::lastedWithin($subscription, $bounds))->count(),
                ])
                ->values()
                ->all(),
            'cohorts' => $subscriptions
                ->groupBy('account_id')
                ->map(fn (Collection $account): array => [
                    'month' => $account->min('created_at')->setTimezone($range->timezone)->format('Y-m'),
                    'stays' => $account->contains(fn (Subscription $subscription): bool => self::stays($subscription, $now)),
                ])
                ->groupBy('month')
                ->sortKeys()
                ->map(fn (Collection $cohort, string $month): array => [
                    'month' => $month,
                    'started' => $cohort->count(),
                    'alive' => $cohort->where('stays', true)->count(),
                    'retention' => Percent::of($cohort->where('stays', true)->count(), $cohort->count()),
                ])
                ->values()
                ->all(),
        ];
    }

    /** Alive now and not cancelling at its period end. */
    private static function stays(Subscription $subscription, CarbonImmutable $now): bool
    {
        return $subscription->isAliveAt($now) && blank($subscription->ends_at);
    }

    /**
     * @param  array{0: int, 1: int}  $bounds
     */
    private static function lastedWithin(Subscription $subscription, array $bounds): bool
    {
        $days = self::lifetimeDays($subscription);

        return $days >= data_get($bounds, 0) && $days < data_get($bounds, 1);
    }

    /** How many whole days an ended subscription lasted. */
    private static function lifetimeDays(Subscription $subscription): int
    {
        return (int) $subscription->created_at->diffInDays($subscription->ends_at);
    }
}
