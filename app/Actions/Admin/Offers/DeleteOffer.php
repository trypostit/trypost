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
    public function __construct(private readonly RecordAdminAction $recordAdminAction) {}

    /**
     * Deletes an offer nobody redeemed, with its claims. One that was
     * redeemed keeps its history: it can only end.
     */
    public function execute(User $admin, Offer $offer): void
    {
        if ($offer->redemptions()->exists()) {
            throw ValidationException::withMessages(['offer' => 'This offer was redeemed, so it can only end.']);
        }

        DB::transaction(function () use ($admin, $offer): void {
            $this->recordAdminAction->execute($admin, AdminActionType::DeleteOffer, $offer, ['slug' => $offer->slug]);
            $offer->accounts()->detach();
            $offer->delete();
        });
    }
}
