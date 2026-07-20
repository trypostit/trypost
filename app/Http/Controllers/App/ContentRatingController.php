<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\ContentRating\StoreContentRatingRequest;
use App\Models\ContentRating;
use Illuminate\Http\Response;

class ContentRatingController extends Controller
{
    /**
     * Records the user's 1-5 rating of a generated piece of content. A rating
     * tied to a specific item is idempotent (rating again updates it); a
     * standalone rating is a new data point. It never blocks anything and
     * answers 204, so the UI can fire it and forget.
     */
    public function store(StoreContentRatingRequest $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $payload = ['rating' => (int) $request->validated('rating')];

        if ($request->filled('rateable_id')) {
            ContentRating::updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'rateable_type' => $request->validated('rateable_type'),
                    'rateable_id' => $request->validated('rateable_id'),
                ],
                $payload,
            );
        } else {
            ContentRating::create([
                'workspace_id' => $workspace->id,
                ...$payload,
            ]);
        }

        return response()->noContent();
    }
}
