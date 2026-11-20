<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Source;
use App\Http\Requests\App\Media\BrowseWebdavRequest;
use App\Services\Media\Sources\WebdavFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class WebdavMediaController extends Controller
{
    public function browse(BrowseWebdavRequest $request): JsonResponse
    {
        abort_unless(Source::Webdav->isEnabled(), Response::HTTP_NOT_FOUND);

        try {
            return response()->json(WebdavFiles::browse((string) $request->validated('path', '')));
        } catch (InvalidArgumentException) {
            return response()->json(['message' => __('posts.composer.media_sources.webdav.load_failed')], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $exception) {
            // The share's address and answer help the operator; the user gets
            // a plain message.
            Log::warning('WebDAV listing failed', ['message' => $exception->getMessage()]);

            return response()->json(['message' => __('posts.composer.media_sources.webdav.load_failed')], Response::HTTP_BAD_GATEWAY);
        }
    }
}
