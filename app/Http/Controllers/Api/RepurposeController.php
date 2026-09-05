<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\CreateRepurpose;
use App\Actions\Repurpose\DeleteRepurpose;
use App\Actions\Repurpose\DisableRepurpose;
use App\Actions\Repurpose\ListRepurposes;
use App\Actions\Repurpose\PauseRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\Repurpose\SourceFormat;
use App\Http\Requests\Api\Repurpose\StoreRepurposeRequest;
use App\Http\Requests\Api\Repurpose\UpdateRepurposeRequest;
use App\Http\Resources\Api\RepurposeItemResource;
use App\Http\Resources\Api\RepurposeResource;
use App\Models\Repurpose;
use App\Support\Repurpose\Templates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class RepurposeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Repurpose::class);

        return RepurposeResource::collection(
            ListRepurposes::execute($request->user()->currentWorkspace),
        );
    }

    public function store(StoreRepurposeRequest $request): JsonResponse
    {
        $this->authorize('create', Repurpose::class);

        $repurpose = CreateRepurpose::execute(
            $request->user()->currentWorkspace,
            $request->user(),
            $request->validated(),
        );

        return (new RepurposeResource($repurpose))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('view', $repurpose);

        return new RepurposeResource($repurpose);
    }

    public function update(UpdateRepurposeRequest $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('update', $repurpose);

        return new RepurposeResource(UpdateRepurpose::execute($repurpose, $request->validated()));
    }

    public function activate(Request $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('update', $repurpose);

        return new RepurposeResource(ActivateRepurpose::execute($repurpose));
    }

    public function pause(Request $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('update', $repurpose);

        return new RepurposeResource(PauseRepurpose::execute($repurpose));
    }

    public function resume(Request $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('update', $repurpose);

        return new RepurposeResource(ResumeRepurpose::execute($repurpose));
    }

    public function disable(Request $request, Repurpose $repurpose): RepurposeResource
    {
        $this->authorize('update', $repurpose);

        return new RepurposeResource(DisableRepurpose::execute($repurpose));
    }

    public function destroy(Request $request, Repurpose $repurpose): JsonResponse
    {
        $this->authorize('delete', $repurpose);

        DeleteRepurpose::execute($repurpose);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    public function items(Request $request, Repurpose $repurpose): AnonymousResourceCollection
    {
        $this->authorize('view', $repurpose);

        return RepurposeItemResource::collection(
            $repurpose->items()->with('posts.postPlatforms:id,post_id,platform,enabled')->latest()->paginate(15),
        );
    }

    public function templates(): JsonResponse
    {
        $this->authorize('viewAny', Repurpose::class);

        return response()->json([
            'data' => Templates::all(),
            'source_formats' => array_map(
                fn (SourceFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
                SourceFormat::cases(),
            ),
        ]);
    }
}
