<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Media\SearchRequest;
use App\Http\Requests\App\Media\TrendingRequest;
use App\Services\UnsplashService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UnsplashController extends Controller
{
    public function search(SearchRequest $request, UnsplashService $unsplash): JsonResponse
    {
        $this->authorize('createPost', $request->user()->currentWorkspace);

        $results = $unsplash->search(
            query: (string) $request->validated('query'),
            page: (int) $request->validated('page', 1),
        );

        return $results === null ? $this->unavailable() : response()->json($results);
    }

    public function trending(TrendingRequest $request, UnsplashService $unsplash): JsonResponse
    {
        $photos = $unsplash->trending(page: (int) $request->validated('page', 1));

        return $photos === null ? $this->unavailable() : response()->json(['results' => $photos]);
    }

    private function unavailable(): JsonResponse
    {
        return response()->json(['message' => __('posts.composer.unsplash.error')], Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
