<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Http\Requests\App\Media\StoreChunkedMediaRequest;
use App\Http\Requests\App\Media\StoreMediaFromUrlRequest;
use App\Http\Resources\App\MediaResource;
use App\Services\Media\ChunkedAssetReceiver;
use App\Services\Media\RemoteMediaImporter;
use App\Services\UnsplashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Brings files in before an owner exists: every completed upload is a
 * temporary upload (`uploads` collection, fresh `upload_token`) that the
 * first save adopts.
 */
class MediaUploadController extends Controller
{
    private const int UNSPLASH_TIMEOUT_SECONDS = 30;

    public function storeChunked(StoreChunkedMediaRequest $request, ChunkedAssetReceiver $receiver): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $receipt = $receiver->receive(
            $workspace,
            $request->user(),
            $request->validated('file_name'),
            $request->getContent(),
            (int) $request->validated('range_start'),
            (int) $request->validated('range_end'),
            (int) $request->validated('total_size'),
            (string) $request->validated('upload_id'),
            $request->duration(),
        );

        $receipt->media?->issueUploadToken();

        return $receipt->toResponse();
    }

    public function storeFromUrl(StoreMediaFromUrlRequest $request, RemoteMediaImporter $importer, UnsplashService $unsplash): MediaResource
    {
        $imported = $importer->import(
            $request->user()->currentWorkspace,
            new RemoteFile(
                $request->validated('url'),
                $request->validated('filename'),
                allowedHosts: Source::Unsplash->downloadHosts(),
                source: Source::Unsplash,
                sourceMeta: $request->attribution(),
            ),
            [MediaType::Image],
            self::UNSPLASH_TIMEOUT_SECONDS,
        );

        if (! $imported->succeeded()) {
            throw ValidationException::withMessages(['url' => __('posts.composer.media_sources.errors.import_failed')]);
        }

        $unsplash->trackDownload($request->validated('download_location'));

        return new MediaResource($imported->media);
    }
}
