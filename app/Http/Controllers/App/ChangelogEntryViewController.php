<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\RecordChangelogEntryView;
use App\Http\Controllers\Controller;
use App\Http\Middleware\App\HandleInertiaRequests;
use App\Http\Requests\App\Changelog\StoreChangelogEntryViewRequest;
use App\Models\ChangelogEntry;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryViewController extends Controller
{
    public function store(StoreChangelogEntryViewRequest $request, ChangelogEntry $changelogEntry): HttpResponse
    {
        $user = $request->user();

        abort_unless(
            config('trypost.changelog.enabled') && $changelogEntry->isPublished(),
            Response::HTTP_NOT_FOUND,
        );

        RecordChangelogEntryView::execute($user, $changelogEntry);

        Cache::forget(HandleInertiaRequests::changelogCardCacheKey($user));

        return response()->noContent();
    }
}
