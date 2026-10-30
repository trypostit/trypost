<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\StartInsightsPdf;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Channel\StoreChannelInsightsPdfRequest;
use App\Http\Resources\App\InsightsPdfExportResource;
use App\Models\SocialAccount;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ChannelInsightsPdfController extends Controller
{
    public function store(StoreChannelInsightsPdfRequest $request, SocialAccount $account): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        if ($request->hasFile('logo') || $request->boolean('remove_logo')) {
            $this->authorize('update', $workspace);
        }
        abort_unless($account->platform->isIncludedInAnalytics(), Response::HTTP_NOT_FOUND);

        $id = StartInsightsPdf::execute(
            $request->user(),
            $workspace,
            $account,
            $request->safe()->only(['range', 'start', 'end', 'labels', 'untagged', 'types', 'sort']),
            [
                'name' => $request->validated('name'),
                'sections' => $request->validated('sections'),
                'columns' => $request->validated('columns', []),
            ],
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        return (new InsightsPdfExportResource($id, InsightsPdfExports::find($id)))
            ->response()
            ->setStatusCode(Response::HTTP_ACCEPTED);
    }
}
