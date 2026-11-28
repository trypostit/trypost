<?php

declare(strict_types=1);

namespace App\Actions\Offer;

use App\Actions\Activity\RecordAdminAction;
use App\Enums\Activity\AdminActionType;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteOffer
{
    public const string REDEEMED_MESSAGE = 'This offer was redeemed, so it can only end.';

    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Deletes an offer nobody redeemed, with its claims. The request checks it
     * first; here the offer's claims (`account_offer`, the rows a redemption
     * writes) are locked before the redemptions are counted again, so a
     * redemption of a claim either landed before and stops the delete, or
     * waits for it and finds its claim gone.
     */
    public function execute(User $admin, Offer $offer): void
    {
        DB::transaction(function () use ($admin, $offer): void {
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $locked->accounts()->newPivotStatement()->where('offer_id', $locked->id)->lockForUpdate()->get(['account_id']);

            if ($locked->redemptions()->exists()) {
                throw ValidationException::withMessages(['offer' => self::REDEEMED_MESSAGE]);
            }

            $this->recordAdminAction->execute($admin, AdminActionType::DeleteOffer, $locked, ['slug' => $locked->slug]);
            $locked->accounts()->detach();
            $locked->delete();
        });
    }
}
