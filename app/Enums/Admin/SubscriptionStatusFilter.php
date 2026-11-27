<?php

declare(strict_types=1);

namespace App\Enums\Admin;

use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where a subscription stands, as the backoffice filters them. Every one is in
 * All, and in at most one other group: a subscription with an end is
 * cancelling or canceled whatever its Stripe status, and one without is in
 * the group of its status. A status no group names (unpaid, paused) leaves a
 * subscription without an end in All only, so the other groups may add up to
 * less than All.
 */
enum SubscriptionStatusFilter: string
{
    case All = 'all';
    case Active = 'active';
    case Cancelling = 'cancelling';
    case PastDue = 'past_due';
    case Trialing = 'trialing';
    case Canceled = 'canceled';

    /**
     * @param  Builder<Subscription>  $query
     */
    public function apply(Builder $query, CarbonInterface $now): void
    {
        match ($this) {
            self::All => null,
            self::Active => $query->where('stripe_status', 'active')->whereNull('ends_at'),
            self::Cancelling => $query->where('ends_at', '>', $now),
            self::PastDue => $query->where('stripe_status', 'past_due')->whereNull('ends_at'),
            self::Trialing => $query->where('stripe_status', 'trialing')->whereNull('ends_at'),
            self::Canceled => $query->where('ends_at', '<=', $now),
        };
    }

    /**
     * How many subscriptions each filter holds, worked out from one read of
     * their status and end: the same groups {@see self::apply()} filters.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     * @return array<string, int>
     */
    public static function counts(Collection $subscriptions, CarbonInterface $now): array
    {
        $grouped = $subscriptions->countBy(fn (Subscription $subscription): string => self::of($subscription, $now)?->value ?? self::All->value);

        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => (int) $grouped->get($status->value, 0)])
            ->put(self::All->value, $subscriptions->count())
            ->all();
    }

    /**
     * The one group a subscription is in, or null when only All holds it.
     */
    private static function of(Subscription $subscription, CarbonInterface $now): ?self
    {
        if ($subscription->ends_at?->greaterThan($now) === true) {
            return self::Cancelling;
        }

        if (filled($subscription->ends_at)) {
            return self::Canceled;
        }

        if ($subscription->stripe_status === 'active') {
            return self::Active;
        }

        if ($subscription->stripe_status === 'past_due') {
            return self::PastDue;
        }

        if ($subscription->stripe_status === 'trialing') {
            return self::Trialing;
        }

        return null;
    }
}
