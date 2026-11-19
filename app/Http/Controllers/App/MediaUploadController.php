<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Media\StoreChunkedMediaRequest;
use App\Services\Media\ChunkedAssetReceiver;
use Illuminate\Http\JsonResponse;

/**
 * Brings files in before an owner exists: every completed upload is a
 * temporary upload (`uploads` collection, fresh `upload_token`) that the
 * first save adopts.
 */
class MediaUploadController extends Controller
{
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
}
