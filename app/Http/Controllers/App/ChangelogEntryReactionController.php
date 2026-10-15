<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\ReactToChangelogEntry;
use App\Enums\Changelog\Reaction;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Changelog\UpdateChangelogEntryReactionRequest;
use App\Models\ChangelogEntry;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryReactionController extends Controller
{
    public function update(UpdateChangelogEntryReactionRequest $request, ChangelogEntry $changelogEntry): Response
    {
        abort_unless($changelogEntry->isVisible(), Response::HTTP_NOT_FOUND);

        ReactToChangelogEntry::execute(
            $request->user(),
            $changelogEntry,
            $request->enum('reaction', Reaction::class),
        );

        return response()->noContent();
    }
}
