<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Dto\Analytics\DateRange;
use App\Models\Subscription;
use App\Support\Percent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Every subscription that ever billed, read once, and what the admin
 * works out from it over time: how many were alive at a moment, which ended
 * in a range and the churn they make. An ended subscription keeps its row
 * with `ends_at`, so every figure is exact for any day.
 */
final readonly class SubscriptionHistory
{
    /** The window the daily churn rate looks back over, in days. */
    public const int ROLLING_DAYS = 30;

    /** @var Collection<int, Subscription> */
    public Collection $ended;

    /**
     * @param  Collection<int, Subscription>  $subscriptions
     */
    public function __construct(public Collection $subscriptions, public CarbonImmutable $now)
    {
        $this->ended = $subscriptions->filter(fn (Subscription $subscription): bool => $subscription->endedBy($now));
    }

    public static function read(): self
    {
        return new self(
            Subscription::query()
                ->billed()
                ->get(['id', 'account_id', 'stripe_status', 'stripe_price', 'quantity', 'trial_ends_at', 'ends_at', 'created_at']),
            CarbonImmutable::now('UTC'),
        );
    }

    /**
     * @return Collection<int, Subscription>
     */
    public function aliveAt(CarbonImmutable $moment): Collection
    {
        return $this->subscriptions->filter(fn (Subscription $subscription): bool => $subscription->isAliveAt($moment));
    }

    /**
     * The subscriptions that ended on the range's days.
     *
     * @return Collection<int, Subscription>
     */
    public function canceledIn(DateRange $range): Collection
    {
        return $this->ended->filter(fn (Subscription $subscription): bool => $subscription->ends_at->between($range->startsAt(), $range->endsAt()));
    }

    /**
     * The share of the subscriptions alive at some point of the range (alive
     * when it starts, or started in it) that ended in it, so a subscription
     * that starts and ends inside the range counts on both sides and the
     * rate never passes 100%.
     */
    public function churnRate(DateRange $range): float
    {
        return Percent::of($this->canceledIn($range)->count(), $this->aliveBetween($range->startsAt(), $range->endsAt())->count());
    }

    /**
     * The subscriptions alive at some moment between the two: started by the
     * last and not ended before the first.
     *
     * @return Collection<int, Subscription>
     */
    private function aliveBetween(CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return $this->subscriptions->filter(fn (Subscription $subscription): bool => $subscription->created_at->lte($until)
            && (blank($subscription->ends_at) || $subscription->ends_at->gte($from)));
    }

    /**
     * How many subscriptions were alive at the end of each of the range's days.
     *
     * @return list<int>
     */
    public function aliveByDay(DateRange $range): array
    {
        return collect($range->calendarDays())
            ->map(fn (string $day): int => $this->aliveAt(self::endOfDay($range, $day))->count())
            ->all();
    }

    /**
     * How many subscriptions ended on each of the range's days.
     *
     * @return list<int>
     */
    public function canceledByDay(DateRange $range): array
    {
        return $range->perDay($this->canceledIn($range)->countBy(fn (Subscription $subscription): string => $range->localDate($subscription->ends_at)));
    }

    /**
     * Each of the range's days, the share of the subscriptions alive at some
     * point of the 30 days up to its end that ended in those 30 days.
     *
     * @return list<float>
     */
    public function churnRateByDay(DateRange $range): array
    {
        return collect($range->calendarDays())
            ->map(fn (string $day): float => $this->rollingChurn(self::endOfDay($range, $day)))
            ->all();
    }

    private function rollingChurn(CarbonImmutable $moment): float
    {
        $from = $moment->subDays(self::ROLLING_DAYS);
        $left = $this->ended->filter(fn (Subscription $subscription): bool => $subscription->ends_at->gt($from) && $subscription->ends_at->lte($moment));

        return Percent::of($left->count(), $this->aliveBetween($from, $moment)->count());
    }

    /** The UTC instant a calendar day of the range ends in the viewer's zone. */
    private static function endOfDay(DateRange $range, string $day): CarbonImmutable
    {
        return DateRange::fromDates($day, $day, $range->timezone)->endsAt();
    }
}
