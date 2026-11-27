<?php

declare(strict_types=1);

namespace App\Enums\Admin;

use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Where a subscription stands, as the backoffice filters them. Each one is in
 * exactly one group: a subscription set to cancel is cancelling whatever its
 * Stripe status, so the groups add up to all.
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
}
