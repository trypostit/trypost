<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\RssFeed\AddRssFeed;
use App\Actions\RssFeed\BuildRssFeedsPageProps;
use App\Actions\RssFeed\DeleteRssFeed;
use App\Actions\RssFeed\RequestRssFeedRefresh;
use App\Actions\RssFeed\UpdateRssFeed;
use App\Http\Requests\App\RssFeed\ListRssFeedsRequest;
use App\Http\Requests\App\RssFeed\RefreshRssFeedsRequest;
use App\Http\Requests\App\RssFeed\StoreRssFeedRequest;
use App\Http\Requests\App\RssFeed\UpdateRssFeedRequest;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RssFeedController extends Controller
{
    public function index(ListRssFeedsRequest $request): Response
    {
        return $this->page($request);
    }

    public function show(ListRssFeedsRequest $request, RssFeed $rssFeed): Response
    {
        $this->authorize('view', $rssFeed);

        return $this->page($request, feed: $rssFeed);
    }

    public function collection(ListRssFeedsRequest $request, RssFeedCollection $rssFeedCollection): Response
    {
        $this->authorize('view', $rssFeedCollection);

        return $this->page($request, collection: $rssFeedCollection);
    }

    public function store(StoreRssFeedRequest $request): RedirectResponse
    {
        $feed = AddRssFeed::execute($request->user()->currentWorkspace, (string) $request->validated('url'), $request->validated('rss_feed_collection_id'));

        if ($request->fromExplore()) {
            return back();
        }

        return to_route('app.create.feeds.show', $feed);
    }

    public function refresh(RefreshRssFeedsRequest $request): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $feedId = $request->validated('rss_feed_id');
        $collectionId = $request->validated('rss_feed_collection_id');

        RequestRssFeedRefresh::execute(
            $workspace,
            $feedId === null ? null : RssFeed::query()->find($feedId),
            $collectionId === null ? null : RssFeedCollection::query()->find($collectionId),
        );

        return back();
    }

    public function update(UpdateRssFeedRequest $request, RssFeed $rssFeed): RedirectResponse
    {
        UpdateRssFeed::execute($rssFeed, $request->validated());

        return back();
    }

    public function destroy(RssFeed $rssFeed): RedirectResponse
    {
        $this->authorize('delete', $rssFeed);

        $viewingFeed = Str::before(url()->previous(), '?') === route('app.create.feeds.show', $rssFeed);

        DeleteRssFeed::execute($rssFeed);

        return $viewingFeed ? to_route('app.create.feeds.index') : back();
    }

    private function page(ListRssFeedsRequest $request, ?RssFeed $feed = null, ?RssFeedCollection $collection = null): Response
    {
        return Inertia::render('create/Feeds', BuildRssFeedsPageProps::execute(
            $request,
            $request->user()->currentWorkspace,
            $feed,
            $collection,
        ));
    }
}
