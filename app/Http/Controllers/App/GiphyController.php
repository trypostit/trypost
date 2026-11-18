<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Http\Requests\App\Media\SearchRequest;
use App\Http\Requests\App\Media\StoreGiphyMediaRequest;
use App\Http\Requests\App\Media\TrendingRequest;
use App\Http\Resources\App\MediaResource;
use App\Services\GiphyService;
use App\Services\Media\RemoteMediaImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The composer's GIPHY picker: search and trending GIFs, and the GIF the user
 * picks brought in as a temporary upload, like any other media source.
 */
class GiphyController extends Controller
{
    private const int IMPORT_TIMEOUT_SECONDS = 30;

    public function search(SearchRequest $request, GiphyService $giphy): JsonResponse
    {
        return $this->respond($giphy->search(
            query: (string) $request->validated('query'),
            page: (int) $request->validated('page', 1),
        ));
    }

    public function trending(TrendingRequest $request, GiphyService $giphy): JsonResponse
    {
        return $this->respond($giphy->trending(page: (int) $request->validated('page', 1)));
    }

    public function store(StoreGiphyMediaRequest $request, RemoteMediaImporter $importer): MediaResource
    {
        $imported = $importer->import(
            $request->user()->currentWorkspace,
            new RemoteFile(
                $request->validated('url'),
                $request->filename(),
                allowedHosts: Source::Giphy->downloadHosts(),
                source: Source::Giphy,
                sourceMeta: $request->attribution(),
            ),
            [MediaType::Image],
            self::IMPORT_TIMEOUT_SECONDS,
        );

        if (! $imported->succeeded()) {
            throw ValidationException::withMessages(['url' => __('posts.composer.media_sources.errors.import_failed')]);
        }

        return new MediaResource($imported->media);
    }

    /**
     * @param  array{results: list<array<string, mixed>>, has_more: bool}|null  $page
     */
    private function respond(?array $page): JsonResponse
    {
        if ($page === null) {
            return response()->json(['message' => __('posts.composer.giphy.error')], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json($page);
    }
}
