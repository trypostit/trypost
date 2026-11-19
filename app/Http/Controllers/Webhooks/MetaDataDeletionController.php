<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Enums\SocialAccount\MetaApp;
use App\Http\Controllers\Controller;
use App\Jobs\Meta\DeleteMetaUserData;
use App\Models\DataDeletionRequest;
use App\Services\Social\Meta\SignedRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MetaDataDeletionController extends Controller
{
    /**
     * Meta's Data Deletion Request callback. The channels go in a queued job;
     * Meta gets back the status page and the code it shows the person.
     */
    public function store(Request $request, string $app): JsonResponse
    {
        $metaApp = MetaApp::from($app);
        $userId = SignedRequest::userId((string) $request->input('signed_request'), $metaApp->secret());

        abort_if($userId === null, SymfonyResponse::HTTP_FORBIDDEN);

        $deletionRequest = DataDeletionRequest::open($metaApp);

        DeleteMetaUserData::dispatch($deletionRequest, $userId);

        return response()->json([
            'url' => route('meta.data-deletion.show', $deletionRequest->confirmation_code),
            'confirmation_code' => $deletionRequest->confirmation_code,
        ]);
    }

    /**
     * The status page Meta links the person to.
     */
    public function show(string $code): Response
    {
        $deletionRequest = DataDeletionRequest::query()->where('confirmation_code', $code)->firstOrFail();

        return Inertia::render('meta/DataDeletionStatus', [
            'confirmationCode' => $deletionRequest->confirmation_code,
            'completed' => $deletionRequest->completed_at !== null,
            'requestedAt' => $deletionRequest->created_at->toIso8601String(),
            'completedAt' => $deletionRequest->completed_at?->toIso8601String(),
        ]);
    }
}
