<?php

declare(strict_types=1);

namespace App\Actions\Admin\Offers;

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
use App\Enums\Offer\OfferState;
use App\Models\Offer;
use App\Models\User;
use Carbon\CarbonInterface;

class EndOffer
{
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Ends the offer now: nobody can claim or redeem it any more, and its
     * history stays. An offer that already ended keeps its end.
     */
    public function execute(User $admin, Offer $offer): void
    {
        if ($offer->state() === OfferState::Ended) {
            return;
        }

        $offer->update(self::endingNow($offer));
        $this->recordAdminAction->execute($admin, AdminActionType::EndOffer, $offer, ['slug' => $offer->slug]);
    }

    /**
     * A scheduled offer never ran, so it starts and ends now: its end never
     * comes before its start.
     *
     * @return array{starts_at?: CarbonInterface, ends_at: CarbonInterface}
     */
    private static function endingNow(Offer $offer): array
    {
        $now = now();

        if ($offer->state() === OfferState::Scheduled) {
            return ['starts_at' => $now, 'ends_at' => $now];
        }

        return ['ends_at' => $now];
    }
}
