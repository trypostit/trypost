<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\RecordChangelogEntryView;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Changelog\StoreChangelogEntryViewRequest;
use App\Models\ChangelogEntry;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryViewController extends Controller
{
    public function store(StoreChangelogEntryViewRequest $request, ChangelogEntry $changelogEntry): HttpResponse
    {
        $user = $request->user();

        abort_unless(
            config('trypost.changelog.enabled')
                && ChangelogEntry::published()->whereKey($changelogEntry->id)->exists(),
            Response::HTTP_NOT_FOUND,
        );

        RecordChangelogEntryView::execute($user, $changelogEntry);

        return response()->noContent();
    }
}
