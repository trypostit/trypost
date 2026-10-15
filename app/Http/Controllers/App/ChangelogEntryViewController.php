<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\RecordChangelogEntryView;
use App\Http\Controllers\Controller;
use App\Models\ChangelogEntry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryViewController extends Controller
{
    public function store(Request $request, ChangelogEntry $changelogEntry): Response
    {
        abort_unless($changelogEntry->isVisible(), Response::HTTP_NOT_FOUND);

        RecordChangelogEntryView::execute($request->user(), $changelogEntry);

        return response()->noContent();
    }
}
