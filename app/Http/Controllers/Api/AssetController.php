<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Media\FindWorkspaceAsset;
use App\Actions\Media\ListWorkspaceAssets;
use App\Http\Requests\Api\Asset\IndexAssetRequest;
use App\Http\Resources\Api\AssetResource;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AssetController extends Controller
{
    public function index(IndexAssetRequest $request): AnonymousResourceCollection
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        return AssetResource::collection(ListWorkspaceAssets::execute(
            $workspace,
            data_get($request->validated(), 'search'),
            data_get($request->validated(), 'type'),
        ));
    }

    public function show(Request $request, Media $media): AssetResource
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $asset = FindWorkspaceAsset::execute($workspace, $media->id);

        abort_if($asset === null, Response::HTTP_NOT_FOUND);

        return new AssetResource($asset);
    }
}
