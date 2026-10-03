<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Media\StartMediaImport;
use App\Http\Requests\App\Media\StoreMediaImportRequest;
use App\Http\Resources\App\MediaImportResource;
use App\Http\Resources\App\MediaImportStartedResource;
use App\Models\Media;
use App\Support\MediaImportStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MediaImportController extends Controller
{
    public function store(StoreMediaImportRequest $request): JsonResponse
    {
        $importIds = StartMediaImport::execute(
            $request->user(),
            $request->user()->currentWorkspace,
            $request->importSource(),
            $request->importPayload(),
        );

        return (new MediaImportStartedResource($importIds))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, string $import): MediaImportResource
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $entry = MediaImportStatus::find($import, $request->user()->id, $workspace->id);

        abort_if($entry === null, Response::HTTP_NOT_FOUND);

        $media = filled(data_get($entry, 'media_id'))
            ? Media::query()->where('workspace_id', $workspace->id)->find(data_get($entry, 'media_id'))
            : null;

        return new MediaImportResource($entry, $media);
    }
}
