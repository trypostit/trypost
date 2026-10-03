<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\RssFeed\ImportRssFeedItemImage;
use App\Actions\RssFeed\SaveRssFeedItemAsIdea;
use App\Http\Resources\App\RssFeedItemImageResource;
use App\Models\RssFeedItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RssFeedItemController extends Controller
{
    public function importImage(RssFeedItem $rssFeedItem): RssFeedItemImageResource
    {
        $this->authorize('update', $rssFeedItem);

        $media = ImportRssFeedItemImage::execute($rssFeedItem);

        return RssFeedItemImageResource::make($media);
    }

    public function saveAsIdea(Request $request, RssFeedItem $rssFeedItem): RedirectResponse
    {
        $this->authorize('update', $rssFeedItem);

        SaveRssFeedItemAsIdea::execute($rssFeedItem, $request->user());

        return back();
    }
}
