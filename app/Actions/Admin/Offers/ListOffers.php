<?php

declare(strict_types=1);

namespace App\Actions\Admin\Offers;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Collection;

class ListOffers
{
    /**
     * Every offer with its claims and redemptions counted, the latest start
     * first.
     *
     * @return Collection<int, Offer>
     */
    public static function execute(): Collection
    {
        return Offer::query()
            ->withCount(['accounts', 'redemptions'])
            ->orderByDesc('starts_at')
            ->orderBy('slug')
            ->get();
    }
}
