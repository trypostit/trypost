<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\Offers\DeleteOffer;
use App\Actions\Admin\Offers\ListOffers;
use App\Actions\Admin\Offers\SaveOffer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Offer\DestroyOfferRequest;
use App\Http\Requests\Admin\Offer\StoreOfferRequest;
use App\Http\Requests\Admin\Offer\UpdateOfferRequest;
use App\Http\Resources\Admin\AdminOfferResource;
use App\Models\Offer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OfferController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/billing/offers/Index', [
            'offers' => AdminOfferResource::collection(ListOffers::execute()),
            'today' => now($request->user()->timezone)->toDateString(),
        ]);
    }

    public function store(StoreOfferRequest $request, SaveOffer $saveOffer): RedirectResponse
    {
        $saveOffer->execute($request->user(), $request->offer());

        return back();
    }

    public function update(UpdateOfferRequest $request, Offer $offer, SaveOffer $saveOffer): RedirectResponse
    {
        $saveOffer->execute($request->user(), $request->offer(), $offer);

        return back();
    }

    public function destroy(DestroyOfferRequest $request, Offer $offer, DeleteOffer $deleteOffer): RedirectResponse
    {
        $deleteOffer->execute($request->user(), $offer);

        return back();
    }
}
