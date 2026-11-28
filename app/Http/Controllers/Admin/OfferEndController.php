<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Offer\EndOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Offer\EndOfferRequest;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;

class OfferEndController extends Controller
{
    public function store(EndOfferRequest $request, Offer $offer, EndOffer $endOffer): RedirectResponse
    {
        $endOffer->execute($request->user(), $offer);

        return back();
    }
}
