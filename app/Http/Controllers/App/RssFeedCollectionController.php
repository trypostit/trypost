<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\RssFeed\CreateRssFeedCollection;
use App\Actions\RssFeed\DeleteRssFeedCollection;
use App\Actions\RssFeed\RenameRssFeedCollection;
use App\Http\Requests\App\RssFeed\StoreRssFeedCollectionRequest;
use App\Http\Requests\App\RssFeed\UpdateRssFeedCollectionRequest;
use App\Models\RssFeedCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class RssFeedCollectionController extends Controller
{
    public function store(StoreRssFeedCollectionRequest $request): RedirectResponse
    {
        CreateRssFeedCollection::execute($request->user()->currentWorkspace, $request->validated());

        return back();
    }

    public function update(UpdateRssFeedCollectionRequest $request, RssFeedCollection $rssFeedCollection): RedirectResponse
    {
        RenameRssFeedCollection::execute($rssFeedCollection, $request->validated());

        return back();
    }

    public function destroy(RssFeedCollection $rssFeedCollection): RedirectResponse
    {
        $this->authorize('delete', $rssFeedCollection);

        $viewingCollection = Str::before(url()->previous(), '?') === route('app.create.feeds.collections.show', $rssFeedCollection);

        DeleteRssFeedCollection::execute($rssFeedCollection);

        return $viewingCollection ? to_route('app.create.feeds.index') : back();
    }
}
