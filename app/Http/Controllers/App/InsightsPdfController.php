<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\StartInsightsPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Insights\StoreInsightsPdfRequest;
use App\Http\Resources\App\InsightsPdfExportResource;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InsightsPdfController extends Controller
{
    public function store(StoreInsightsPdfRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            $this->authorize('update', $workspace);
        }

        $id = StartInsightsPdf::execute(
            $request->user(),
            $workspace,
            null,
            $request->safe()->only(['range', 'start', 'end', 'channels', 'sort', 'direction']),
            [
                'name' => $request->validated('name'),
                'sections' => $request->validated('sections'),
                'columns' => $request->validated('columns', []),
            ],
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        return (new InsightsPdfExportResource($id, InsightsPdfExports::find($id), $workspace))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(Request $request, string $export): InsightsPdfExportResource
    {
        $entry = InsightsPdfExports::findFor($request->user(), $export);

        abort_if(blank($entry), Response::HTTP_NOT_FOUND);

        return new InsightsPdfExportResource($export, $entry);
    }

    public function download(Request $request, string $export): StreamedResponse
    {
        $entry = InsightsPdfExports::findFor($request->user(), $export);

        abort_unless(
            data_get($entry, 'status') === InsightsPdfExports::READY && Storage::exists((string) data_get($entry, 'path')),
            Response::HTTP_NOT_FOUND,
        );

        return Storage::download((string) data_get($entry, 'path'), (string) data_get($entry, 'filename'), [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
