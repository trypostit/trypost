<?php

declare(strict_types=1);

namespace App\Actions\Admin\Offers;

use App\Actions\Admin\RecordAdminAction;
use App\Enums\Admin\AdminActionType;
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
     * first; the locked read here catches a redemption that landed since.
     */
    public function execute(User $admin, Offer $offer): void
    {
        DB::transaction(function () use ($admin, $offer): void {
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);

            if ($locked->redemptions()->exists()) {
                throw ValidationException::withMessages(['offer' => self::REDEEMED_MESSAGE]);
            }

            $this->recordAdminAction->execute($admin, AdminActionType::DeleteOffer, $locked, ['slug' => $locked->slug]);
            $locked->accounts()->detach();
            $locked->delete();
        });
    }
}
