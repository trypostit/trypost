<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Enums\Admin\SubscriptionStatusFilter;
use App\Models\Account;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

class ListSubscriptions
{
    /**
     * Every subscription that ever billed, the newest first, by where it
     * stands and by its owner's name or email.
     *
     * @return Builder<Subscription>
     */
    public static function execute(SubscriptionStatusFilter $status, ?string $search = null): Builder
    {
        return Subscription::query()
            ->where('type', Account::SUBSCRIPTION_NAME)
            ->whereNotIn('stripe_status', ['incomplete', 'incomplete_expired'])
            ->with(['owner.owner', 'owner.offers'])
            ->when($search, fn (Builder $query, string $term) => $query->whereHas('owner.owner', fn (Builder $owner) => $owner
                ->where(fn (Builder $match) => $match->whereLike('name', "%{$term}%")->orWhereLike('email', "%{$term}%"))))
            ->tap(fn (Builder $query) => $status->apply($query, now('UTC')))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
