<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Media\StoreSignedUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUploadRequest;
use App\Http\Resources\Api\MediaUploadResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends Controller
{
    public function store(StoreUploadRequest $request, string $token, StoreSignedUpload $upload): JsonResponse
    {
        $media = $upload->handle(
            (string) $request->query('workspace_id'),
            $request->file('media'),
            $token,
            (int) $request->query('expires'),
        );

        return MediaUploadResource::make($media)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
