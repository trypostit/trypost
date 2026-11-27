<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Cashier\Subscription as CashierSubscription;
use Stripe\Subscription as StripeSubscription;

class Subscription extends CashierSubscription
{
    use HasUuids;

    /** Stripe statuses of a subscription that is paid for. */
    public const array PAYING_STATUSES = [StripeSubscription::STATUS_ACTIVE, StripeSubscription::STATUS_PAST_DUE];

    /** Stripe statuses of a checkout that never billed. */
    public const array NEVER_BILLED_STATUSES = [StripeSubscription::STATUS_INCOMPLETE, StripeSubscription::STATUS_INCOMPLETE_EXPIRED];

    /** Incomplete / incomplete_expired never became billable. */
    public function becameBillable(): bool
    {
        return ! $this->incomplete() && $this->stripe_status !== StripeSubscription::STATUS_INCOMPLETE_EXPIRED;
    }

    /**
     * The account subscriptions that ever billed: the SQL twin of
     * {@see self::becameBillable()}.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeBilled(Builder $query): Builder
    {
        return $query
            ->where('subscriptions.type', Account::SUBSCRIPTION_NAME)
            ->whereNotIn('subscriptions.stripe_status', self::NEVER_BILLED_STATUSES);
    }

    /**
     * Not ended yet: no end, or one still to come.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where(fn (Builder $live): Builder => $live
            ->whereNull('subscriptions.ends_at')
            ->orWhere('subscriptions.ends_at', '>', now()));
    }

    /**
     * The account subscriptions paid for now: live, active or past due. The
     * SQL twin of {@see self::isPaying()}.
     *
     * @param  Builder<Subscription>  $query
     * @return Builder<Subscription>
     */
    public function scopePaying(Builder $query): Builder
    {
        return $query
            ->where('subscriptions.type', Account::SUBSCRIPTION_NAME)
            ->whereIn('subscriptions.stripe_status', self::PAYING_STATUSES)
            ->live();
    }

    public function isPaying(): bool
    {
        if ($this->type !== Account::SUBSCRIPTION_NAME) {
            return false;
        }

        if (! collect(self::PAYING_STATUSES)->contains($this->stripe_status)) {
            return false;
        }

        return ! $this->endedBy(now());
    }

    /** Started by then and not ended yet. */
    public function isAliveAt(CarbonInterface $moment): bool
    {
        if ($this->created_at->gt($moment)) {
            return false;
        }

        return ! $this->endedBy($moment);
    }

    /** Its end came by then. */
    public function endedBy(CarbonInterface $moment): bool
    {
        if ($this->ends_at === null) {
            return false;
        }

        return $this->ends_at->lte($moment);
    }
}
