<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Media\SearchRequest;
use App\Http\Requests\App\Media\TrendingRequest;
use App\Services\GiphyService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The composer's GIPHY picker: search and trending GIFs. The GIF the user
 * picks is imported through the media import queue, like the other sources.
 */
class GiphyController extends Controller
{
    public function search(SearchRequest $request, GiphyService $giphy): JsonResponse
    {
        return $this->respond($giphy->search(
            query: $request->safe()->string('query')->value(),
            page: $request->safe()->integer('page', 1),
        ));
    }

    public function trending(TrendingRequest $request, GiphyService $giphy): JsonResponse
    {
        return $this->respond($giphy->trending(page: $request->safe()->integer('page', 1)));
    }

    /**
     * @param  array{results: list<array<string, mixed>>, has_more: bool}|null  $page
     */
    private function respond(?array $page): JsonResponse
    {
        if (blank($page)) {
            return response()->json(['message' => __('posts.composer.giphy.error')], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json($page);
    }
}
