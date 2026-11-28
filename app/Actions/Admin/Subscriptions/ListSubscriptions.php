<?php

declare(strict_types=1);

namespace App\Actions\Admin\Subscriptions;

use App\Enums\Admin\SubscriptionStatusFilter;
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
        return $status->apply(self::matching($search), now('UTC'))
            ->with(['owner.owner', 'owner.redeemedOffers'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * How many of the subscriptions the search finds each status filter
     * holds, from one read of their status and end.
     *
     * @return array<string, int>
     */
    public static function statusCounts(?string $search = null): array
    {
        return SubscriptionStatusFilter::counts(
            self::matching($search)->withOnly([])->get(['stripe_status', 'ends_at']),
            now('UTC'),
        );
    }

    /**
     * @return Builder<Subscription>
     */
    private static function matching(?string $search): Builder
    {
        return Subscription::query()
            ->billed()
            ->when($search, fn (Builder $query, string $term) => $query->whereHas('owner.owner', fn (Builder $owner) => $owner->matching($term)));
    }
}
