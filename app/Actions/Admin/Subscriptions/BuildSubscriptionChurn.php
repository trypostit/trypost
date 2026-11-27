<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Dto\Analytics\DateRange;
use App\Models\Subscription;
use App\Support\Admin\SubscriptionPlans;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class BuildSubscriptionChurn
{
    public const int RECENT = 50;

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
    public static function execute(DateRange $range): array
    {
        $now = CarbonImmutable::now('UTC');
        $first = $range->startsAt();
        $subscriptions = BuildSubscriptionOverview::billable();
        $ended = $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->ends_at !== null && $subscription->ends_at->lte($now));
        $endedInPeriod = $ended->filter(fn (Subscription $subscription): bool => $subscription->ends_at->between($first, $range->endsAt()));
        $prices = SubscriptionPlans::byPrice();
        $days = collect($range->calendarDays());

        $recent = Subscription::query()
            ->whereIn('id', $endedInPeriod->pluck('id'))
            ->with(['owner.owner', 'owner.offers'])
            ->orderByDesc('ends_at')
            ->limit(self::RECENT)
            ->get();

        return [
            'summary' => [
                'canceled' => $endedInPeriod->count(),
                'churn_rate' => Percent::of(
                    $endedInPeriod->count(),
                    $subscriptions->filter(fn (Subscription $subscription): bool => BuildSubscriptionOverview::aliveAt($subscription, $first))->count(),
                ),
                'average_lifetime_days' => (int) round((float) $endedInPeriod->avg(fn (Subscription $subscription): int => (int) $subscription->created_at->diffInDays($subscription->ends_at))),
                'cancelling' => $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->ends_at !== null && $subscription->ends_at->gt($now))->count(),
            ],
            'days' => $days->all(),
            'canceled_by_day' => BuildSubscriptionOverview::perDay($days, $endedInPeriod->map(fn (Subscription $subscription): string => $range->localDate($subscription->ends_at))),
            'churn_rate_by_day' => $days->map(fn (string $day): float => BuildSubscriptionOverview::rollingChurn($subscriptions, $ended, BuildSubscriptionOverview::endOfDay($range, $day)))->all(),
            'recent' => $recent->map(fn (Subscription $subscription): array => [
                'id' => $subscription->id,
                'user_id' => $subscription->owner?->owner?->id,
                'user_name' => $subscription->owner?->owner?->name,
                'user_email' => $subscription->owner?->owner?->email,
                'plan' => SubscriptionPlans::describe($subscription->stripe_price, $prices),
                'started_at' => $subscription->created_at->toIso8601String(),
                'ended_at' => $subscription->ends_at->toIso8601String(),
                'lifetime_days' => (int) $subscription->created_at->diffInDays($subscription->ends_at),
                'offers' => $subscription->owner?->offers->pluck('slug')->values()->all() ?? [],
            ])->values()->all(),
            'lifetimes' => collect(self::LIFETIMES)
                ->map(fn (array $bounds, string $label): array => [
                    'label' => $label,
                    'count' => $ended->filter(fn (Subscription $subscription): bool => self::lastedWithin($subscription, $bounds))->count(),
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
        return BuildSubscriptionOverview::aliveAt($subscription, $now) && $subscription->ends_at === null;
    }

    /**
     * @param  array{0: int, 1: int}  $bounds
     */
    private static function lastedWithin(Subscription $subscription, array $bounds): bool
    {
        $days = (int) $subscription->created_at->diffInDays($subscription->ends_at);

        return $days >= $bounds[0] && $days < $bounds[1];
    }
}
