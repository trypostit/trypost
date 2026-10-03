<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Post\StoreLinkPreviewMediaRequest;
use App\Http\Resources\App\MediaResource;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Social\LinkCard\LinkCardFetcher;
use Illuminate\Validation\ValidationException;

/**
 * "Replace link preview with media": imports the card's image as a temporary
 * upload that the first save adopts, like any other composer upload.
 */
class LinkPreviewMediaController extends Controller
{
    private const int TIMEOUT_SECONDS = 30;

    public function __invoke(StoreLinkPreviewMediaRequest $request, LinkCardFetcher $fetcher, RemoteMediaImporter $importer): MediaResource
    {
        $imageUrl = $fetcher->fetch($request->validated('url'))?->imageUrl;

        if (blank($imageUrl)) {
            throw ValidationException::withMessages(['url' => __('posts.composer.link_preview.no_image')]);
        }

        $imported = $importer->import(
            $request->user()->currentWorkspace,
            RemoteFile::fromUrl($imageUrl),
            [MediaType::Image],
            self::TIMEOUT_SECONDS,
            followRedirects: true,
        );

        if (! $imported->succeeded()) {
            throw ValidationException::withMessages(['url' => __('posts.composer.media_sources.errors.import_failed')]);
        }

        return new MediaResource($imported->media);
    }
}
