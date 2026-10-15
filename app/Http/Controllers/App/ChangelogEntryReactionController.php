<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\ReactToChangelogEntry;
use App\Enums\Changelog\Reaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Changelog\UpdateChangelogEntryReactionRequest;
use App\Models\ChangelogEntry;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryReactionController extends Controller
{
    public function update(UpdateChangelogEntryReactionRequest $request, ChangelogEntry $changelogEntry): HttpResponse
    {
        abort_unless(
            config('trypost.changelog.enabled')
                && ChangelogEntry::published()->whereKey($changelogEntry->id)->exists(),
            Response::HTTP_NOT_FOUND,
        );

        ReactToChangelogEntry::execute(
            $request->user(),
            $changelogEntry,
            $request->enum('reaction', Reaction::class),
        );

        return response()->noContent();
    }
}
