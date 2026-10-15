<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\RecordChangelogEntryView;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Changelog\StoreChangelogEntryViewRequest;
use App\Models\ChangelogEntry;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryViewController extends Controller
{
    public function store(StoreChangelogEntryViewRequest $request, ChangelogEntry $changelogEntry): Response
    {
        abort_unless($changelogEntry->isVisible(), Response::HTTP_NOT_FOUND);

        RecordChangelogEntryView::execute($request->user(), $changelogEntry);

        return response()->noContent();
    }
}
