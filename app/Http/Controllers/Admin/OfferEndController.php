<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Offers\EndOffer;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OfferEndController extends Controller
{
    public function store(Request $request, Offer $offer, EndOffer $endOffer): RedirectResponse
    {
        $endOffer->execute($request->user(), $offer);

        return redirect()->route('admin.billing.offers.index');
    }
}
