<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Webhook\CreateWebhook;
use App\Actions\Webhook\DeleteWebhook;
use App\Actions\Webhook\ReplayWebhookLog;
use App\Actions\Webhook\RotateWebhookSecret;
use App\Actions\Webhook\SendWebhookTest;
use App\Actions\Webhook\UpdateWebhook;
use App\Http\Requests\Api\Webhook\StoreWebhookRequest;
use App\Http\Requests\Api\Webhook\UpdateWebhookRequest;
use App\Http\Resources\Api\WebhookLogResource;
use App\Http\Resources\Api\WebhookResource;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Webhook::class);

        $webhooks = Webhook::query()
            ->where('workspace_id', $request->user()->currentWorkspace->id)
            ->orderByDesc('created_at')
            ->get();

        return WebhookResource::collection($webhooks);
    }

    public function store(StoreWebhookRequest $request, WebhookService $webhookService): JsonResponse
    {
        $this->authorize('create', Webhook::class);

        try {
            $webhook = CreateWebhook::execute(
                $request->user()->currentWorkspace,
                $request->validated(),
                $webhookService,
            );
        } catch (RuntimeException $e) {
            return $this->endpointFailure($e);
        }

        return (new WebhookResource($webhook->makeVisible('signing_secret')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Webhook $webhook): WebhookResource
    {
        $this->authorize('view', $this->webhookInWorkspace($request, $webhook));

        return new WebhookResource($webhook->makeVisible('signing_secret'));
    }

    public function update(UpdateWebhookRequest $request, Webhook $webhook, WebhookService $webhookService): WebhookResource|JsonResponse
    {
        $this->authorize('update', $this->webhookInWorkspace($request, $webhook));

        try {
            $webhook = UpdateWebhook::execute($webhook, $request->validated(), $webhookService);
        } catch (RuntimeException $e) {
            return $this->endpointFailure($e);
        }

        return new WebhookResource($webhook);
    }

    public function sendTest(Request $request, Webhook $webhook, WebhookService $webhookService): JsonResponse
    {
        $this->authorize('update', $this->webhookInWorkspace($request, $webhook));

        try {
            SendWebhookTest::execute($webhook, $webhookService);
        } catch (RuntimeException $e) {
            return $this->endpointFailure($e);
        }

        return response()->json(['tested' => true]);
    }

    public function rotateSecret(Request $request, Webhook $webhook): WebhookResource
    {
        $this->authorize('update', $this->webhookInWorkspace($request, $webhook));

        $webhook = RotateWebhookSecret::execute($webhook);

        return new WebhookResource($webhook->makeVisible('signing_secret'));
    }

    public function logs(Request $request, Webhook $webhook): AnonymousResourceCollection
    {
        $this->authorize('view', $this->webhookInWorkspace($request, $webhook));

        $logs = $webhook->logs()
            ->orderByDesc('created_at')
            ->paginate(15);

        return WebhookLogResource::collection($logs);
    }

    public function replay(Request $request, Webhook $webhook, WebhookLog $webhookLog): JsonResponse
    {
        $webhook = $this->webhookInWorkspace($request, $webhook);

        if ($webhookLog->webhook_id !== $webhook->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $this->authorize('replay', [$webhookLog, $webhook]);

        ReplayWebhookLog::execute($webhook, $webhookLog);

        return response()->json(['replayed' => true]);
    }

    public function destroy(Request $request, Webhook $webhook): JsonResponse
    {
        $this->authorize('delete', $this->webhookInWorkspace($request, $webhook));

        DeleteWebhook::execute($webhook);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function webhookInWorkspace(Request $request, Webhook $webhook): Webhook
    {
        if ($webhook->workspace_id !== $request->user()->currentWorkspace->id) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return $webhook;
    }

    private function endpointFailure(RuntimeException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => ['endpoint' => [$e->getMessage()]],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
