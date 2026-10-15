<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Changelog\ReactToChangelogEntry;
use App\Enums\Changelog\Reaction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\App\HandleInertiaRequests;
use App\Http\Requests\App\Changelog\UpdateChangelogEntryReactionRequest;
use App\Models\ChangelogEntry;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ChangelogEntryReactionController extends Controller
{
    public function update(UpdateChangelogEntryReactionRequest $request, ChangelogEntry $changelogEntry): HttpResponse
    {
        $user = $request->user();

        abort_unless(
            config('trypost.changelog.enabled') && $changelogEntry->isPublished(),
            Response::HTTP_NOT_FOUND,
        );

        ReactToChangelogEntry::execute(
            $user,
            $changelogEntry,
            $request->enum('reaction', Reaction::class),
        );

        Cache::forget(HandleInertiaRequests::changelogCardCacheKey($user));

        return response()->noContent();
    }
}
