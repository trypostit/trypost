<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Media\StartMediaImport;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Post\StoreLinkPreviewMediaRequest;
use App\Http\Resources\App\MediaImportStartedResource;
use App\Services\Social\LinkCard\LinkCardFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Replace link preview with media": reads the card's image (the composer
 * already fetched the card, so it comes from the cache) and queues its
 * import as a temporary upload the first save adopts, like any other
 * composer import.
 */
class LinkPreviewMediaController extends Controller
{
    public function __invoke(StoreLinkPreviewMediaRequest $request, LinkCardFetcher $fetcher): JsonResponse
    {
        $imageUrl = $fetcher->fetch($request->validated('url'))?->imageUrl;

        if (blank($imageUrl)) {
            throw ValidationException::withMessages(['url' => __('posts.composer.link_preview.no_image')]);
        }

        $importIds = StartMediaImport::execute(
            $request->user(),
            $request->user()->currentWorkspace,
            RemoteFile::fromUrl($imageUrl),
            [MediaType::Image],
        );

        return (new MediaImportStartedResource($importIds))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
