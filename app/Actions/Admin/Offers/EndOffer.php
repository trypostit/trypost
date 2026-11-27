<?php

declare(strict_types=1);

namespace App\Actions\Admin\Offers;

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
use App\Models\Offer;
use App\Models\User;

class EndOffer
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Ends the offer now: nobody can claim or redeem it any more, and its
     * history stays. An offer that already ended keeps its end.
     */
    public function execute(User $admin, Offer $offer): void
    {
        if ($offer->ends_at?->isFuture() === false) {
            return;
        }

        $offer->update(['ends_at' => now()]);
        $this->recordAdminAction->execute($admin, AdminActionType::EndOffer, $offer, ['slug' => $offer->slug]);
    }
}
